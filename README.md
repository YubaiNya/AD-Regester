# Cloud Desktop Registration Portal

A small PHP application for controlled Active Directory account registration. It is designed for LNMP/BaoTa-style deployments and supports LDAP/LDAPS or Samba RPC provisioning.

## Features

- Employee-ID based account registration
- Password complexity validation
- Managed invite codes with expiry and usage limits
- Admin console for invite management, registration logs, and deleting users created by this system
- AD group assignment after registration
- SQLite-backed audit data under `storage/data/`

## Directory layout

```text
ad-register/
?? public/              # Web root
?  ?? index.php         # Registration page
?  ?? admin.php         # Admin console
?? src/                 # PHP application code
?? storage/             # Logs, rate-limit files, SQLite data; must be writable by PHP-FPM
?? bin/check_ad.php     # CLI connectivity check
?? .env.example         # Redacted configuration template
?? nginx-site.sample.conf
```

## Deployment

1. Create a site and point the web root to:

   ```bash
   /www/wwwroot/ad-register/public
   ```

2. Upload the project to `/www/wwwroot/ad-register`.

3. Copy the environment template and fill in your own values:

   ```bash
   cd /www/wwwroot/ad-register
   cp .env.example .env
   ```

4. Configure at least these values in `.env`:

   ```ini
   AD_BACKEND=ldap
   AD_HOST=ad.example.local
   AD_BIND_USER=ad-register-svc@example.local
   AD_BIND_PASSWORD=
   AD_DOMAIN=example.local
   AD_BASE_DN=DC=example,DC=local
   AD_USER_BASE_DN=CN=Users,DC=example,DC=local
   AD_GROUP_DN=CN=VDI_Users,CN=Users,DC=example,DC=local
   INVITE_REQUIRED=true
   ADMIN_USERNAME=admin
   ADMIN_PASSWORD_HASH=
   ```

   Generate the admin password hash with:

   ```bash
   php -r "echo password_hash('CHANGE_ME', PASSWORD_DEFAULT), PHP_EOL;"
   ```

5. Enable the PHP LDAP extension. If using `AD_BACKEND=samba`, install Samba client utilities:

   ```bash
   apt-get install -y samba-common-bin
   ```

6. Set writable permissions for runtime data:

   ```bash
   chown -R www:www /www/wwwroot/ad-register/storage
   chmod -R 770 /www/wwwroot/ad-register/storage
   ```

7. Run the AD connectivity check:

   ```bash
   cd /www/wwwroot/ad-register
   php bin/check_ad.php
   ```

## Admin console

Open:

```text
https://your-site.example/admin.php
```

The admin console can:

- create invite codes manually or automatically;
- set invite-code expiry time;
- set maximum usage count;
- delete invite codes;
- view registration and admin-operation logs;
- delete AD users that were created and recorded by this system.

The admin database is stored at `storage/data/app.sqlite`. Do not commit this file.

## Registration rules

- The login account is the employee ID.
- Employee ID accepts digits only.
- Password must contain uppercase letters, lowercase letters, digits, and special characters.
- Password must not contain the employee ID.

## Backend choices

### LDAP / LDAPS

Use `AD_BACKEND=ldap` when the AD server supports secure password changes via LDAPS or StartTLS.

```ini
AD_BACKEND=ldap
AD_HOST=ad.example.local
AD_PORT=636
AD_USE_SSL=true
AD_START_TLS=false
```

### Samba RPC

Use `AD_BACKEND=samba` when password setup is handled through Samba RPC.

```ini
AD_BACKEND=samba
AD_SAMBA_DOMAIN=EXAMPLE
AD_SAMBA_USER=ad-register-svc
AD_SAMBA_PASSWORD=
AD_USE_SSL=false
AD_PORT=389
```

## Security notes

- Never commit `.env`, `.env.local`, runtime logs, SQLite databases, or real credentials.
- Keep `INVITE_REQUIRED=true` for public-facing deployments.
- Prefer `ADMIN_PASSWORD_HASH`; leave `ADMIN_PASSWORD` empty.
- Use a dedicated AD service account with the minimum delegated permissions needed to create users, reset passwords, and update the target group.
- Put only `public/` behind the web server root.
- Enable HTTPS in production.

## License

This project is published with an **All Rights Reserved** license. See `LICENSE`.
