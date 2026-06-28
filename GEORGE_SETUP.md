# BBW MRP — Developer Setup

## Overview
This is a PHP + MySQL app. When you push changes to the `main` branch on GitHub, the live server at `http://24.199.122.134` automatically deploys within seconds. You do not need to do anything with the server.

---

## 1. Prerequisites
- [XAMPP](https://www.apachefriends.org/) installed (provides Apache + PHP + MySQL locally)
- [Git](https://git-scm.com/) installed
- A GitHub account with access to `https://github.com/dap416/bbw-mrp`

---

## 2. Clone the Repo

Open a terminal and run:

```bash
git clone https://github.com/dap416/bbw-mrp.git C:/xampp/htdocs/bbw-mrp
```

---

## 3. Create the Local Config File

The file `includes/config.local.php` is not in the repo (it contains secrets). Create it manually:

**`C:/xampp/htdocs/bbw-mrp/includes/config.local.php`**

```php
<?php

    return [
        'db' => [
            'host' => 'localhost',
            'name' => 'bbw_raw_inv',
            'user' => 'bbwadmin',
            'pass' => '2o)xA1frq2Te',
        ],
        'dev' => [
            'login_email'    => 'your@email.com',
            'login_password' => 'yourpassword',
        ],
    ];
```

> The `dev` block auto-fills the login form when running on localhost — set it to your own credentials.

---

## 4. Set Up the Local Database

1. Start XAMPP and make sure **Apache** and **MySQL** are running.
2. Open [http://localhost/phpmyadmin](http://localhost/phpmyadmin)
3. Create a new database named `bbw_raw_inv`
4. Create a user `bbwadmin` with password `2o)xA1frq2Te` and grant it full access to `bbw_raw_inv`
5. Import the latest database dump (ask Devin for `bbw_raw_inv.sql`)

Or via command line:

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root -e "CREATE DATABASE bbw_raw_inv; CREATE USER 'bbwadmin'@'localhost' IDENTIFIED BY '2o)xA1frq2Te'; GRANT ALL ON bbw_raw_inv.* TO 'bbwadmin'@'localhost';"
"C:/xampp/mysql/bin/mysql.exe" -u bbwadmin -p2o)xA1frq2Te bbw_raw_inv < path\to\bbw_raw_inv.sql
```

---

## 5. Access the App Locally

With XAMPP running, open: [http://localhost/bbw-mrp](http://localhost/bbw-mrp)

---

## 6. GitHub Authentication

To push changes, Git needs to authenticate with GitHub. The easiest method is a **Personal Access Token**:

1. Go to **GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic)**
2. Click **Generate new token**
3. Check the **repo** scope, set an expiration, generate it
4. Copy the token — you'll use it as your password the first time you push

When Git prompts for credentials, enter your GitHub **username** and the **token** as the password. Git will cache it after the first use.

---

## 7. Daily Workflow

```bash
# Before starting work — get latest changes
git pull

# After making changes
git add -A
git commit -m "description of what you changed"
git push
```

That's it — the live server updates automatically when you push.

---

## Live Server
- **URL:** http://24.199.122.134
- **Deploys automatically** on every push to `main`
