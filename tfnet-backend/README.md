# TF Net Backend

PHP backend for TF Net. It talks to an Omada controller for hotspot/voucher and client management, and it uses InfinityFree for the database.

## Setup

1. Copy `.env.example` to `.env`.
2. Fill in your database and Omada credentials.
3. Deploy the same values as environment variables on Render.

## Omada notes

- `OMADA_BASE_URL` must be reachable from the backend host.
- For an online/cloud-access controller, use the cloud connector host from the Omada session URL, not the private interface address.
- The controller UI may show `https://192.168.0.208:443`, but that is only the local management address. Render cannot reach it directly unless you put the backend on the same network or through a VPN/tunnel.
- `OMADA_SITE_ID` is optional. If it is blank, the backend will ask Omada for the available sites and use the first one it finds.

## Environment variables

- `DB_HOST`
- `DB_USER`
- `DB_PASS`
- `DB_NAME`
- `DB_PORT`
- `JWT_SECRET`
- `OMADA_BASE_URL`
- `OMADA_ID`
- `OMADA_SITE_ID`
- `OMADA_CLIENT_ID`
- `OMADA_CLIENT_SECRET`
- `OMADA_EMAIL`
- `OMADA_PASSWORD`
