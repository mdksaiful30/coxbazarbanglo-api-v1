<?php
return [
 'account_sid'=>env('TWILIO_ACCOUNT_SID'),
 'auth_token'=>env('TWILIO_AUTH_TOKEN'),
 'sms_from'=>env('TWILIO_SMS_FROM'),
 'whatsapp_from'=>env('TWILIO_WHATSAPP_FROM'),
 'whatsapp_content_sid'=>env('TWILIO_WHATSAPP_CONTENT_SID'),
 'status_callback'=>env('TWILIO_STATUS_CALLBACK'),
];
