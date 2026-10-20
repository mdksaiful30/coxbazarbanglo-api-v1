# Public privacy policy

Upload `public/privacy-policy.html` to the website's actual public document root. On the current hosting layout this is `public_html/privacy-policy.html`, beside `index.php`, not inside another nested `public` folder.

Once uploaded, the Google Play privacy-policy URL is:

https://coxbazarbanglo.learnwithzaman.com/privacy-policy.html

The deletion-request instructions can be linked directly at:

https://coxbazarbanglo.learnwithzaman.com/privacy-policy.html#data-deletion

The page is standalone HTML with compiled Tailwind styles embedded. It does not require login, JavaScript, a CSS CDN, Laravel route changes, a database migration, or an application rebuild. It has been checked at phone and desktop widths with JavaScript disabled. No deployment to the live hosting account was performed.

The privacy contact supplied by the owner is `info@learnwithzaman.com`. The policy covers the current staff accounts, guest records, bookings, financial records, documents, preferences, messaging, and session/audit data. It uses purpose-based retention because no fixed retention period was provided. The rest-house operator should confirm the retention description and keep the policy aligned with actual operations and the Play Data safety answers.

Google Play also requires privacy information to be accessible inside the app. Creating this public HTML page does not add a link to an already compiled Android bundle. If the app is changed to add that link before the first upload, rebuild the initial `1.0.0+1` bundle.

Policy reference consulted: https://support.google.com/googleplay/android-developer/answer/10144311
