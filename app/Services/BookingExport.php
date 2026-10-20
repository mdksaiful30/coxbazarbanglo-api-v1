<?php
namespace App\Services;
use Symfony\Component\Process\Process;
class BookingExport {
 public function render(array $confirmation,string $format):string{
  abort_unless(in_array($format,['pdf','jpg']),422,'Unsupported export format.');
  $browser=config('booking_exports.browser_binary');
  if(!$browser){foreach(['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','/usr/bin/chromium','/usr/bin/chromium-browser','/usr/bin/google-chrome'] as $candidate)if(is_file($candidate)){$browser=$candidate;break;}}
  abort_unless($browser&&is_file($browser),503,'Configure EXPORT_BROWSER_BINARY with an installed Chrome or Chromium executable.');
  $directory=storage_path('framework/booking-exports');if(!is_dir($directory))mkdir($directory,0700,true);
  $output=$directory.'/'.bin2hex(random_bytes(16)).'.'.$format;
  try{
   $html=view('booking-confirmation',['d'=>$confirmation])->render();
   $environment=null;if(PHP_OS_FAMILY==='Windows'){$windows=getenv('SystemRoot')?:getenv('SYSTEMROOT');if($windows)$environment=['SystemRoot'=>$windows,'WINDIR'=>$windows,'TEMP'=>sys_get_temp_dir(),'TMP'=>sys_get_temp_dir()];}
   $process=new Process([config('booking_exports.node_binary'),base_path('tools/render-booking.mjs')],base_path(),$environment,json_encode(['html'=>$html,'format'=>$format,'output'=>$output,'browser'=>$browser],JSON_UNESCAPED_UNICODE));
   $process->setTimeout(config('booking_exports.timeout',45));$process->run();
   if (!$process->isSuccessful()) \Illuminate\Support\Facades\Log::warning('Booking renderer process failed', ['exit_code'=>$process->getExitCode(),'error'=>mb_substr($process->getErrorOutput(),0,1500)]);
   abort_unless($process->isSuccessful()&&is_file($output),503,'Booking renderer unavailable. Verify Node.js, npm dependencies and Chromium configuration.');
   abort_if(filesize($output)>15*1024*1024,422,'Export is too large.');
   return file_get_contents($output);
  }catch(\Symfony\Component\Process\Exception\ProcessTimedOutException $e){abort(503,'Booking export timed out. Please retry.');}
  finally{if(is_file($output))unlink($output);}
 }
}

