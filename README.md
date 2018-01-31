# Allow2php

[![Join the chat at https://gitter.im/Allow2/Allow2php](https://badges.gitter.im/Allow2/Allow2php.svg)](https://gitter.im/Allow2/Allow2php?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge&utm_content=badge)

refer to https://github.com/Allow2/Allow2.github.io/wiki for more details.

todo: Port https://github.com/Allow2/Allow2node to a PHP library
(NOTE: Allow2WP wordpress plugin has an embedded implementation, so we can use that to extract the necessary parts into the library, but then also should have that plugin leverage this library - tease it out)

Oh, and we should build this library to be easy to install via composer, packagist, etc

# Curl examples

to get you started:

## 1. Pairing

First, pair with Allow2 (this is using the username/password method):

```sh
curl -H "Content-Type: application/json" -X POST -d '{"user": "ALLOW2_ACCOUNT_EMAIL", "pass":"ALLOW2_ACCOUNT_PASS", "deviceToken": "jJ5GOIaJ028Ywt6K", "name":"Test Device 1" }' https://app.allow2.com:8443/api/pairDevice
```

this returns a payload with information that you pass back to the app to persist for future use against the service:
```json
{
  "status":"success",
  "pairId":12345,
  "token":"6742b233-de46-4c86-2ac9-7b9e5729f999",
  "name":"Test Device 1",
  "userId": 1234,
  "children":[
    { "id":682, "name":"Bob" },
    { "id":691, "name":"Grace" },
    { "id":1795,"name":"Fred"}
  ]
}
```

## 2. Checking and Logging Usage

```sh
curl -H "Content-Type: application/json" -X POST -d '{"userId": 1234, "pairToken":"6742b2f3-de46-4c86-8ac9-7b9e532cf999", "deviceToken": "jJ5GOIaJ028Ywt6K", "tz": "Australia/Brisbane", "childId": "682", "activities": [{ "id": 7, "log": true }] }' https://api.allow2.com:9443/serviceapi/check
```


}
