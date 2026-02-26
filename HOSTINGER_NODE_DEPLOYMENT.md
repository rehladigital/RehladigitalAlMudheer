# Hostinger Node Deployment Wrapper

This repository is primarily a PHP application.  
To support Hostinger's Node deployment flow, a lightweight `server.js` wrapper is included.

## What it does

- Exposes `GET /health` for runtime checks
- If `PHP_APP_URL` is set, redirects all requests to that URL (preserving path/query)
- If `PHP_APP_URL` is not set, shows an informational page

## Required in Hostinger Node app

- Start command: `npm start`
- Environment variable (recommended): `PHP_APP_URL=https://pm.rehladigital.ca`

## Notes

- This Node wrapper does **not** run the PHP backend itself.
- Use this when Hostinger requires a Node-compatible project structure.
