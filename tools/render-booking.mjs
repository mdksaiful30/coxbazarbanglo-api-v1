import puppeteer from 'puppeteer-core';
let input='';for await(const chunk of process.stdin)input+=chunk;
const {html,format,output,browser}=JSON.parse(input);
if(!['pdf','jpg'].includes(format))throw new Error('Unsupported format');
let instance;
try{
 instance=await puppeteer.launch({executablePath:browser,headless:true,timeout:20000});
 const page=await instance.newPage();await page.setViewport({width:794,height:1123,deviceScaleFactor:2});
 await page.setJavaScriptEnabled(false);await page.setRequestInterception(true);
 page.on('request',request=>request.url().startsWith('data:')||request.url()==='about:blank'?request.continue():request.abort());
 await page.setContent(html,{waitUntil:'load',timeout:20000});await page.emulateMediaType('print');
 if(format==='pdf')await page.pdf({path:output,format:'A4',printBackground:true,margin:{top:'10mm',right:'10mm',bottom:'10mm',left:'10mm'},preferCSSPageSize:true});
 else await page.screenshot({path:output,type:'jpeg',quality:94,fullPage:true});
 process.stdout.write('ok');
}catch(error){process.stderr.write('Booking export renderer failed. Verify Node.js, Chromium and system permissions.');process.exitCode=1}
finally{if(instance)await instance.close()}
