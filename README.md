# 🛠 Utility Belt
A collection of things we use on every Craft CMS site

## Features
### Logs

Automatically installs [ether/logs](https://github.com/ether/logs).

### Live Preview

Locks the default live preview target to the `api/preview` Next JS endpoint. 
Requires the `FRONTEND_URL` env var.

### Revalidator

Adds dynamic Next JS revalidation support. Uses the section URI and any
additionally defined revalidate URI's (see a Section settings in Craft CP).  
Calls the `api/revalidate` Next JS endpoint.

Requires the `FRONTEND_URL` env var.  
Requires the `REVALIDATE_TOKEN` env var, that must match the token on the frontend.

## Environment Variables

```dotenv
FRONTEND_URL=http://localhost:3000
REVALIDATE_TOKEN=unique-secret-token-shared-with-frontend
```