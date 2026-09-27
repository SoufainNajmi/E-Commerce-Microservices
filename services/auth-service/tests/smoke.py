#!/usr/bin/env python3
"""Exercise the live Auth API without printing credentials or tokens."""
import json
import secrets
import sys
import urllib.error
import urllib.request

base = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080"

def call(method, path, status, body=None, token=None):
    headers = {"Accept": "application/json", "Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    request = urllib.request.Request(base + path, data=json.dumps(body).encode() if body is not None else None, headers=headers, method=method)
    try:
        response = urllib.request.urlopen(request, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    assert response.status == status, f"{method} {path}: expected {status}, got {response.status}"
    data = json.load(response)
    if path != "/health":
        assert data["success"] is (status < 400)
    print(f"PASS {method} {path}: {status}")
    return data

call("GET", "/health", 200)
password = secrets.token_hex(16) + "Aa1!"
email = "smoke-" + secrets.token_hex(8) + "@example.test"
registered = call("POST", "/api/auth/register", 201, {"name": "Smoke Customer", "email": email, "password": password, "password_confirmation": password})
assert registered["data"]["user"]["role"] == "CUSTOMER"
assert "password" not in registered["data"]["user"]
login = call("POST", "/api/auth/login", 200, {"email": email, "password": password})
token = login["data"]["token"]
call("GET", "/api/auth/me", 200, token=token)
new = call("POST", "/api/auth/refresh", 200, {}, token)["data"]["token"]
call("GET", "/api/auth/me", 401, token=token)
call("POST", "/api/auth/logout", 200, {}, new)
call("GET", "/api/auth/me", 401, token=new)
call("POST", "/api/auth/logout", 200, {}, registered["data"]["token"])
print("Live authentication flow passed.")
