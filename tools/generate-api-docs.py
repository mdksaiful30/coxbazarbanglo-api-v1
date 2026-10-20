"""Regenerate documentation from Laravel routes; preserve reviewed schema definitions."""
from pathlib import Path
import json,re,subprocess,copy
ROOT=Path(__file__).resolve().parents[1]
def write(p,d): (ROOT/p).write_text(json.dumps(d,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
base=json.loads((ROOT/'docs/api-schema-base.json').read_text(encoding='utf-8'))
routes=json.loads(subprocess.check_output(['php',str(ROOT/'tools/export-api-routes.php')],cwd=ROOT,text=True,encoding='utf-8'))
def obj(fields,required=None):return {'type':'object','properties':fields,'required':required or list(fields)}
def st(example=None):return {'type':'string',**({'example':example} if example is not None else {})}
integer={'type':'integer','example':1}
schemas=base['components']['schemas']
new={
 '/auth/preferences':obj({'theme':{'type':'string','enum':['light','dark','system'],'example':'dark'},'language':{'type':'string','enum':['en','bn'],'example':'en'}}),
 '/auth/forgot-password':obj({'email':st('{{email}}')}),
 '/auth/reset-password':obj({'email':st('{{email}}'),'token':st('{{reset_token}}'),'password':st('{{new_password}}'),'password_confirmation':st('{{new_password}}')}),
 '/bookings/{id}/share':obj({'channel':{'type':'string','enum':['email','sms','whatsapp'],'example':'email'},'format':{'type':'string','enum':['text','pdf','jpg'],'example':'pdf'},'version':integer},['channel','version']),
 '/auth/mobile/login':obj({'email':st('{{email}}'),'password':st('{{password}}'),'device_name':st('Postman device')}),
}
reference={
 'properties':obj({'name':st('Rest house'),'address':st("Cox’s Bazar"),'timezone':st('Asia/Dhaka')},['name']),
 'buildings':obj({'property_id':integer,'name':st('Main building')}),
 'floors':obj({'building_id':integer,'number':{'type':'integer','example':3},'name':st('Third floor')}),
 'rooms':obj({'floor_id':integer,'code':st('R401'),'name':st('New room'),'ac_type':{'type':'string','enum':['ac','non_ac']},'bed_type':st('double'),'room_type':st('standard'),'capacity':{'type':'integer','example':2},'bookable':{'type':'boolean','example':False},'housekeeping':{'type':'string','enum':['unverified','ready','needs_cleaning','cleaning']}}),
 'guest-categories':obj({'code':st('D'),'name':st('New category'),'max_nights':{'type':'integer','example':10}},['code','name'])
}
def resolve(schema):
 if '$ref' in schema:return resolve(schemas[schema['$ref'].split('/')[-1]])
 if 'allOf' in schema:
  out=obj({},[])
  for x in schema['allOf']:
   x=resolve(x);out['properties'].update(x.get('properties',{}));out['required']+=x.get('required',[])
  return out
 return schema
def example(schema,key=''):
 schema=resolve(schema)
 if 'example' in schema:return schema['example']
 if 'const' in schema:return schema['const']
 if 'enum' in schema:return schema['enum'][0]
 t=schema.get('type','object');t=t[0] if isinstance(t,list) else t
 if t=='object':return {k:example(v,k) for k,v in schema.get('properties',{}).items() if k in schema.get('required',[]) or k in ['guest_count','reference_letter','notes','notify_guest']}
 if t=='array':return [example(schema.get('items',integer),key)]
 if t=='integer':return 1
 if t=='boolean':return False
 if key in ['check_in_at','starts_at']:return '2027-01-01T14:00:00+06:00'
 if key in ['check_out_at','ends_at']:return '2027-01-05T12:00:00+06:00'
 if schema.get('format')=='date':return '2027-01-01'
 return {'name':'Example Guest','mobile':'+8801712345678','email':'guest@example.test','reason':'Approved office correction','amount_minor':10000,'password':'{{new_password}}','password_confirmation':'{{new_password}}','current_password':'{{password}}','check_in_time':'14:00','check_out_time':'12:00','effective_from':'2027-01-01','reference':'Office reference','notes':'','device_name':'Postman device'}.get(key,'Example '+key)
paths={};items=[];inventory=[]
for route in routes:
 variants=[route['uri'][len('api/v1'):]]
 for key in ['resource','entity','action','format']:
  if '{'+key+'}' not in route['uri']:continue
  values=route['wheres'][key].strip('()').split('|')
  variants=[p.replace('{'+key+'}',v) for p in variants for v in values]
 for path in variants:
  for method in route['methods']:
   if method=='HEAD':continue
   mw=route['middleware'];admin='admin' in mw;idem='idempotent' in mw;public='auth:sanctum' not in mw
   original=base['paths'].get(path,{}).get(method.lower(),{})
   op=copy.deepcopy(original) if original else {'responses':{'200':{'description':'Success'},'401':{'description':'Authentication required'},'403':{'description':'Forbidden'},'404':{'description':'Not found'},'422':{'description':'Validation error'},'429':{'description':'Rate limited'}}}
   op['summary']=method+' '+path;op['operationId']=method.lower()+'_'+re.sub('[^a-zA-Z0-9]+','_',path).strip('_')
   op['description']=('Public endpoint. ' if public else 'Administrator only. ' if admin else 'Authenticated active staff/admin. ')+('Requires Idempotency-Key; reuse only for the same request body. ' if idem else '')
   if path.endswith(('/approve','/reject','/return-for-correction','/check-in','/check-out','/cancel','/mark-no-show')) and path.startswith('/bookings/'):op['description']+='Administrator enforced by booking service. '
   op['security']=[] if public else [{'bearerAuth':[]}]
   op['parameters']=[{'name':k,'in':'path','required':True,'schema':{'type':'integer','minimum':1}} for k in re.findall(r'{(\w+)}',path)]
   if idem:op['parameters'].append({'name':'Idempotency-Key','in':'header','required':True,'schema':st()})
   query={}
   if path=='/availability':query={'check_in_at':'2027-01-01T14:00:00+06:00','check_out_at':'2027-01-05T12:00:00+06:00'}
   elif path in ['/calendar','/reports','/reports/export']:query={'start':'2027-01-01','end':'2027-01-31'}
   for k,v in query.items():op['parameters'].append({'name':k,'in':'query','required':True,'schema':st(v)})
   if path=='/availability':op['parameters'].append({'name':'exclude_booking_id','in':'query','required':False,'schema':integer})
   if method=='GET' and path in ['/guests','/bookings']:
    for k in ['search','page']+(['status','per_page'] if path=='/bookings' else []):op['parameters'].append({'name':k,'in':'query','required':False,'schema':integer if k in ['page','per_page'] else st()})
   schema=new.get(path)
   resource=path.strip('/').split('/')[0]
   if resource in reference and method in ['POST','PUT']:schema=reference[resource]
   if method in ['POST','PUT','PATCH']:
    if schema:op['requestBody']={'required':True,'content':{'application/json':{'schema':schema}}}
    elif 'requestBody' not in op:op['requestBody']={'required':False,'content':{'application/json':{'schema':obj({},[])}}}
   else:op.pop('requestBody',None)
   binary=path.endswith(('/exports/pdf','/exports/jpg','/reports/export','/download'))
   mime='application/pdf' if path.endswith('/exports/pdf') else 'image/jpeg' if path.endswith('/exports/jpg') else 'text/csv' if path.endswith('/reports/export') else 'application/octet-stream' if binary else 'application/json'
   if binary:op['responses']['200']={'description':'Authenticated private download. Save response bytes as a file.','content':{mime:{'schema':{'type':'string','format':'binary'}}}}
   if path.endswith('/share'):
    op['responses'].pop('200',None);op['responses']['202']={'description':'Queued only; inspect delivery history for status.'};op['responses']['503']={'description':'Messaging provider is not configured.'};op['description']+='Recipient is the saved booking guest; arbitrary recipients are prohibited. Email supports PDF/JPG attachments; SMS and WhatsApp use text/approved templates. Use native device sharing for WhatsApp file attachments.'
   if '/exports/' in path:op['responses']['503']={'description':'Configure Node.js, puppeteer-core and a local Chromium browser.'}
   paths.setdefault(path,{})[method.lower()]=op
   url='{{base_url}}/api/v1'+re.sub(r'{(\w+)}',lambda m:'{{'+('rate_id' if m[1]=='rateId' else 'booking_id' if path.startswith('/bookings/') else 'id')+'}}',path)
   if query:
    from urllib.parse import urlencode
    url+='?'+urlencode(query)
   headers=[{'key':'Accept','value':mime}]
   if idem:headers.append({'key':'Idempotency-Key','value':'{{request_idempotency_key}}'})
   request={'method':method,'header':headers,'url':url,'description':op['description']}
   if public:request['auth']={'type':'noauth'}
   events=[]
   if idem:events.append({'listen':'prerequest','script':{'type':'text/javascript','exec':['pm.variables.set("request_idempotency_key", pm.collectionVariables.get("retry_idempotency_key") || pm.variables.replaceIn("{{$guid}}"));']}})
   if 'requestBody' in op:
    content=op['requestBody']['content']
    if 'multipart/form-data' in content:request['body']={'mode':'formdata','formdata':[{'key':'file','type':'file','src':[],'description':'Choose a PDF/JPG/PNG reference letter, max 5 MB.'}]}
    elif 'application/x-www-form-urlencoded' in content:
     body=example(content['application/x-www-form-urlencoded']['schema'])
     request['body']={'mode':'urlencoded','urlencoded':[{'key':k,'value':str(v),'type':'text'} for k,v in body.items()]}
     headers.append({'key':'Content-Type','value':'application/x-www-form-urlencoded'})
     if path=='/messaging/twilio/status':headers.append({'key':'X-Twilio-Signature','value':'{{twilio_signature}}','description':'Generate with the provider SDK using the exact callback URL and form fields.'})
    else:
     body=example(next(iter(content.values()))['schema'])
     if 'version' in body:body['version']='__BOOKING_VERSION__'
     raw=json.dumps(body,ensure_ascii=False,indent=2).replace('"__BOOKING_VERSION__"','{{booking_version}}')
     request['body']={'mode':'raw','raw':raw,'options':{'raw':{'language':'json'}}};headers.append({'key':'Content-Type','value':'application/json'})
   checks=[]
   if path=='/auth/mobile/login':checks=['pm.test("Login succeeded",()=>pm.response.to.have.status(200));','if(pm.response.code===200) pm.collectionVariables.set("token",pm.response.json().data.token);']
   elif path.startswith('/bookings') and not binary and path not in ['/bookings/{id}/shares','/bookings/{id}/share']:checks=['if(pm.response.code<300){const d=pm.response.json().data;if(d && d.id && d.version){pm.collectionVariables.set("booking_id",d.id);pm.collectionVariables.set("booking_version",d.version);}}']
   if checks:events.append({'listen':'test','script':{'type':'text/javascript','exec':checks}})
   items.append({'name':method+' '+path,'request':request,**({'event':events} if events else {})});inventory.append((method,path))
base['paths']=dict(sorted(paths.items()));base['info']['version']='1.2.0';write('docs/openapi.json',base)
items.sort(key=lambda i: (0 if i['name']=='POST /auth/mobile/login' else 1,i['name']))
variables={'base_url':'https://coxbazarbanglo.learnwithzaman.com','token':'','email':'admin@cox-resthouse.test','password':'REPLACE_WITH_YOUR_PASSWORD','new_password':'REPLACE_WITH_STRONG_NEW_PASSWORD','reset_token':'','id':'1','booking_id':'1','booking_version':'1','rate_id':'1','retry_idempotency_key':'','twilio_signature':''}
collection={'info':{'name':"Cox’s Bazar Rest House API",'schema':'https://schema.getpostman.com/json/collection/v2.1.0/collection.json','description':'Run login first. Mutations change data: run individual operations in a test environment. Set booking_id/version from current detail. Leave retry_idempotency_key blank for new actions, pin it only for an identical retry. Binary requests return private files; use Send and Download. Twilio callback requires a valid provider signature.'},'auth':{'type':'bearer','bearer':[{'key':'token','value':'{{token}}','type':'string'}]},'variable':[{'key':k,'value':v} for k,v in variables.items()],'item':items}
write('docs/postman.json',collection)
assert len(items)==sum(len(v) for v in paths.values())==len(set(inventory))
(ROOT/'docs/API-COVERAGE.md').write_text('# REST API coverage\n\n'+str(len(items))+' method/path operations match the registered Laravel API routes (HEAD omitted; constrained resources, actions and export formats expanded).\n\nRegenerate with `python tools/generate-api-docs.py`. The script retains reviewed request schemas in `docs/api-schema-base.json`; update those when validation changes.\n\n| Method | Path |\n|---|---|\n'+'\n'.join('| '+m+' | `'+p+'` |' for m,p in sorted(inventory))+'\n',encoding='utf-8')
print(f'Generated and verified {len(items)} API operations in OpenAPI and Postman.')


