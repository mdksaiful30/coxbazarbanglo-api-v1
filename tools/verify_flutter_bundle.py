"""Verify and name a local Flutter release; never reads private signing passwords."""
from pathlib import Path
import argparse, hashlib, json, re, shutil, struct, subprocess, tempfile, zipfile
parser=argparse.ArgumentParser()
parser.add_argument('--project',default='G:/apps-development/coxbanglo')
parser.add_argument('--suffix',default='ui-refresh')
args=parser.parse_args()
assert re.fullmatch(r'[a-z0-9-]+',args.suffix)
app=Path(args.project).resolve()
assert app.as_posix().lower()=='g:/apps-development/coxbanglo'
source=app/'build/app/outputs/bundle/release/app-release.aab'
version_match=re.search(r'^version:\s*(\d+\.\d+\.\d+)\+(\d+)\s*$',(app/'pubspec.yaml').read_text(encoding='utf8'),re.M)
assert version_match, 'Expected version name and build number in pubspec.yaml'
version_name,version_code=version_match.groups()
jdk=Path('C:/Program Files/Android/Android Studio/jbr/bin')
result=subprocess.run([str(jdk/'jarsigner.exe'),'-verify',str(source)],capture_output=True,text=True)
assert result.returncode==0 and 'jar verified.' in result.stdout,'Bundle signature verification failed'
certificates=[]
for options in [['-jarfile',str(source)],['-file',str(app/'android/upload-certificate.pem')]]:
    out=subprocess.run([str(jdk/'keytool.exe'),'-printcert',*options],capture_output=True,text=True,check=True).stdout
    certificates.append(re.search(r'SHA256:\s*([A-F0-9:]+)',out).group(1))
assert certificates[0]==certificates[1],'Unexpected upload certificate'
alignment=[]
with zipfile.ZipFile(source) as z:
    assert z.testzip() is None
    for file in sorted((app/'assets/rooms').glob('*.jpg')):
        assert z.read('base/assets/flutter_assets/assets/rooms/'+file.name)==file.read_bytes(),file.name
    for name in z.namelist():
        if name.startswith('base/lib/') and name.endswith('.so'):
            data=z.read(name);endian='<' if data[5]==1 else '>'
            if data[4]==2:
                offset=struct.unpack_from(endian+'Q',data,32)[0];size,count=struct.unpack_from(endian+'HH',data,54)
                values=[struct.unpack_from(endian+'Q',data,offset+i*size+48)[0] for i in range(count) if struct.unpack_from(endian+'I',data,offset+i*size)[0]==1]
            else:
                offset=struct.unpack_from(endian+'I',data,28)[0];size,count=struct.unpack_from(endian+'HH',data,42)
                values=[struct.unpack_from(endian+'I',data,offset+i*size+28)[0] for i in range(count) if struct.unpack_from(endian+'I',data,offset+i*size)[0]==1]
            assert min(values)>=16384
            alignment.append({'library':name,'minimum_load_alignment':min(values)})
cache=Path('C:/Users/USER/.gradle/caches/modules-2/files-2.1');jars=[]
for group in cache.iterdir():
    if not group.is_dir():continue
    for artifact in group.iterdir():
        if not artifact.is_dir():continue
        versions=[p for p in artifact.iterdir() if p.is_dir() and list(p.glob('*/*.jar'))]
        if not versions:continue
        version=artifact/'1.18.3' if group.name=='com.android.tools.build' and artifact.name=='bundletool' else max(versions,key=lambda p:p.stat().st_mtime)
        matches=[p for p in version.glob('*/*.jar') if not p.name.endswith(('-sources.jar','-javadoc.jar'))]
        if matches:jars.append(matches[0].as_posix())
classpath='-cp\n"'+';'.join(jars)+'"\ncom.android.tools.build.bundletool.BundleToolMain\n'
with tempfile.TemporaryDirectory(prefix='cox-bundle-verify-') as temp:
    argfile=Path(temp)/'bundletool.args'
    argfile.write_text(classpath+'validate\n--bundle='+source.as_posix()+'\n',encoding='utf8')
    result=subprocess.run([str(jdk/'java.exe'),'@'+str(argfile)],capture_output=True,text=True)
    assert result.returncode==0,result.stderr[-2000:]
    argfile.write_text(classpath+'dump\nmanifest\n--bundle='+source.as_posix()+'\n--module=base\n',encoding='utf8')
    manifest=subprocess.run([str(jdk/'java.exe'),'@'+str(argfile)],capture_output=True,text=True,check=True).stdout
assert f'android:versionCode="{version_code}"' in manifest and f'android:versionName="{version_name}"' in manifest
assert 'com.learnwithzaman.coxbazarbanglo' in manifest
out=app/f'releases/coxbazarbanglo-{version_name}-{version_code}-{args.suffix}.aab'
shutil.copy2(source,out)
sha=hashlib.sha256(out.read_bytes()).hexdigest()
out.with_suffix('.sha256').write_text(sha+'  '+out.name+'\n',encoding='utf8')
p=app/'docs/RELEASE-VERIFICATION.json';report=json.loads(p.read_text(encoding='utf8'))
report.update({'file':str(source),'upload_file':str(out),'bytes':out.stat().st_size,'sha256':sha,'flutter_tests':33,'jar_signature_verified':True,'upload_certificate_matches':True,'bundletool_validate':'passed (1.18.3)','native_elf_alignment':alignment,'room_illustrations_embedded':6,'changes':'Disallow past dates for new bookings; generate PDF and JPG booking/payment summaries locally with Bengali text support.','flutter_web_build':'Not rebuilt for this Android release.'})
report.update({'version_name':version_name,'version_code':int(version_code)})
p.write_text(json.dumps(report,indent=2)+'\n',encoding='utf8')
print(json.dumps({'upload_file':str(out),'bytes':out.stat().st_size,'sha256':sha,'signature':'verified','bundletool':'passed','version':f'{version_name}+{version_code}','room_artwork':6},indent=2))
