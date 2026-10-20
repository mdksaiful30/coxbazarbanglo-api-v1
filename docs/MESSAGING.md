# Messaging configuration

Email requires real SMTP settings. SMS and WhatsApp use Twilio's Messages API with account credentials, an approved sender and a public HTTPS callback URL. WhatsApp uses an approved Content SID and variables 1=booking number, 2=Dhaka arrival timestamp, 3=departure, 4=rooms, 5=BDT total.

Callback: POST `/api/v1/messaging/twilio/status`. It is publicly routable but validates X-Twilio-Signature using the official SDK, exact configured URL and original form values. It also verifies AccountSid. Duplicate and older callbacks do not regress delivered/read state.

Set environment variables listed in .env.example, clear configuration cache, run queue workers and scheduler, then test using your staging recipient. Keep credentials private. Recipient permission, approved sender/template and actual channel availability must be confirmed through your provider account. No real provider credentials were supplied or real messages sent during installation.

Regular retries skip records with an accepted provider ID. External delivery is at least once: provider acceptance followed by a process crash before saving the SID can still produce an uncertain result. Reconcile uncertain delivery before retrying. The account must support the destination region and WhatsApp business messaging requirements.

Implementation references: https://www.twilio.com/docs/messaging/api/message-resource and https://www.twilio.com/docs/usage/security .
