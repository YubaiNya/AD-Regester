# 云桌面 AD 注册门户

这是一个用于受控开通 Active Directory 云桌面账号的轻量级 PHP 应用。用户通过工号、姓名、邮箱和邀请码提交注册信息，系统会自动在 AD 中创建账号、设置密码，并把账号加入指定的云桌面权限组。

项目适合部署在 LNMP / 宝塔面板一类环境中，Web 根目录只需要指向 `public/`。后台数据使用 SQLite 保存在 `storage/data/`，运行日志、限流文件和临时文件也都放在 `storage/` 下。

## 主要功能

- 使用工号作为 AD 登录账号
- 校验密码长度与复杂度
- 支持后台管理的邀请码，可设置过期时间和最大使用次数
- 支持旧版单一邀请码配置
- 注册成功后自动加入指定 AD 组，默认是 `VDI_Users`
- 后台可查看邀请码、注册用户和操作日志
- 后台可删除由本系统创建并记录的 AD 用户
- 支持 LDAP / LDAPS / StartTLS 开通
- 支持 Samba RPC 方式创建账号
- 使用 SQLite 保存审计数据和后台状态

## 目录结构

```text
ad-register/
├─ public/                    # Web 根目录
│  ├─ index.php               # 用户注册页
│  ├─ admin.php               # 管理后台
│  └─ assets/                 # 页面样式
├─ src/                       # PHP 应用代码
├─ storage/                   # 运行数据目录，必须允许 PHP-FPM 写入
│  ├─ data/                   # SQLite 数据库
│  ├─ logs/                   # 应用日志
│  ├─ ratelimit/              # 限流文件
│  └─ tmp/                    # 临时文件
├─ bin/check_ad.php           # AD 连通性检查脚本
├─ .env.example               # 配置模板
└─ nginx-site.sample.conf     # Nginx 示例配置
```

## 运行要求

- PHP 8.x
- Nginx 或其他支持 PHP-FPM 的 Web 服务
- PHP 扩展：`ldap`、`pdo_sqlite`
- 建议启用 `mbstring` 或 `iconv`，用于 AD 密码编码
- 一个具备创建用户、设置密码、加入组权限的 AD 服务账号
- 如果使用 `AD_BACKEND=samba`，服务器还需要安装 Samba 客户端工具

## 部署步骤

1. 上传项目到服务器，例如：

   ```bash
   /www/wwwroot/ad-register
   ```

2. 将站点根目录指向项目的 `public/`：

   ```bash
   /www/wwwroot/ad-register/public
   ```

3. 复制配置模板：

   ```bash
   cd /www/wwwroot/ad-register
   cp .env.example .env
   ```

4. 编辑 `.env`，至少配置以下内容：

   ```ini
   APP_NAME="Cloud Desktop Registration Portal"
   APP_ENV=production
   APP_DEBUG=false

   AD_BACKEND=ldap
   AD_HOST=ad.example.local
   AD_BIND_USER=ad-register-svc@example.local
   AD_BIND_PASSWORD=
   AD_DOMAIN=example.local
   AD_BASE_DN=DC=example,DC=local
   AD_USER_BASE_DN=CN=Users,DC=example,DC=local
   AD_GROUP_CN=VDI_Users
   AD_GROUP_DN=CN=VDI_Users,CN=Users,DC=example,DC=local

   INVITE_REQUIRED=true

   ADMIN_USERNAME=admin
   ADMIN_PASSWORD_HASH=
   ADMIN_PASSWORD=
   ```

5. 生成后台管理员密码哈希，并写入 `ADMIN_PASSWORD_HASH`：

   ```bash
   php -r "echo password_hash('CHANGE_ME', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   生产环境建议只使用 `ADMIN_PASSWORD_HASH`，并保持 `ADMIN_PASSWORD` 为空。

6. 确认 PHP 扩展可用。LDAP 后端需要启用 `ldap`，后台数据库需要启用 `pdo_sqlite`。

   如果使用 Samba 后端，可安装：

   ```bash
   apt-get install -y samba-common-bin
   ```

7. 设置运行目录权限：

   ```bash
   chown -R www:www /www/wwwroot/ad-register/storage
   chmod -R 770 /www/wwwroot/ad-register/storage
   ```

8. 检查 AD 连通性：

   ```bash
   cd /www/wwwroot/ad-register
   php bin/check_ad.php
   ```

## Nginx 配置

仓库提供了 `nginx-site.sample.conf` 作为参考。核心原则是只暴露 `public/`：

```nginx
root /www/wwwroot/ad-register/public;
index index.php index.html;
```

不要把站点根目录直接指向项目根目录。即使额外加了访问限制，也建议从部署层面避免暴露 `.env`、`src/`、`storage/`、`bin/` 等敏感路径。

## 管理后台

部署完成后访问：

```text
https://your-site.example/admin.php
```

后台支持：

- 创建邀请码，也可以留空让系统自动生成
- 设置邀请码过期时间
- 设置邀请码最大使用次数
- 删除或停用邀请码
- 查看系统注册用户
- 删除由本系统创建并记录的 AD 用户
- 查看注册、邀请码、登录和删除操作日志

后台数据默认保存在：

```text
storage/data/app.sqlite
```

不要提交这个数据库文件，也不要提交任何真实运行日志或凭据。

## 注册规则

- 登录账号使用员工工号
- 工号只允许数字
- 工号长度由 `USERNAME_MIN_LENGTH` 和 `USERNAME_MAX_LENGTH` 控制，最大不超过 20 位
- 显示名称可为空，留空时默认使用工号
- 邮箱为可选项，但填写时必须是合法邮箱格式
- 密码长度由 `PASSWORD_MIN_LENGTH` 控制
- 启用 `PASSWORD_REQUIRE_COMPLEXITY=true` 时，密码必须同时包含：
  - 大写字母
  - 小写字母
  - 数字
  - 特殊字符
- 密码不能包含工号

## 邀请码模式

推荐保持：

```ini
INVITE_REQUIRED=true
```

此时注册页会使用后台管理的邀请码。邀请码可设置有效期、使用次数和备注，适合公开访问或半公开访问的部署场景。

如果需要兼容旧版单一邀请码，可以设置：

```ini
INVITE_REQUIRED=false
INVITE_CODE=YOUR_FIXED_CODE
```

当 `INVITE_CODE` 为空且 `INVITE_REQUIRED=false` 时，注册页不会要求邀请码。公开部署不建议这样配置。

## AD 后端

### LDAP / LDAPS

当 AD 支持 LDAPS 或 StartTLS，并且服务账号具备创建用户、设置密码和加入组权限时，推荐使用 LDAP 后端：

```ini
AD_BACKEND=ldap
AD_HOST=ad.example.local
AD_PORT=636
AD_USE_SSL=true
AD_START_TLS=false
AD_TLS_VERIFY=true
```

如果使用 StartTLS，可改为：

```ini
AD_PORT=389
AD_USE_SSL=false
AD_START_TLS=true
```

LDAP 后端会先创建禁用账号，完成密码设置和加组后再按配置启用账号。账号状态可通过以下配置控制：

```ini
AD_ENABLE_ACCOUNT=true
AD_PASSWORD_NEVER_EXPIRES=false
AD_CHANGE_PASSWORD_AT_NEXT_LOGON=false
```

### Samba RPC

当环境中密码设置更适合通过 Samba RPC 完成时，可以使用 Samba 后端：

```ini
AD_BACKEND=samba
AD_HOST=ad.example.local
AD_SAMBA_NET_PATH=/usr/bin/net
AD_SAMBA_DOMAIN=EXAMPLE
AD_SAMBA_USER=ad-register-svc
AD_SAMBA_PASSWORD=
AD_SAMBA_TIMEOUT=25
AD_SAMBA_LDAP_POST_UPDATE=true
```

Samba 后端会调用 `net rpc user add` 创建用户，并通过 `net rpc group addmem` 加入默认组。启用 `AD_SAMBA_LDAP_POST_UPDATE=true` 时，系统会在创建后尝试通过 LDAP 补充 `userPrincipalName`、`displayName`、`mail` 等属性。

## 常用配置

```ini
# 应用
APP_NAME="Cloud Desktop Registration Portal"
APP_TIMEZONE=Asia/Shanghai

# 邀请码
INVITE_REQUIRED=true
INVITE_CODE=

# 注册规则
USERNAME_MIN_LENGTH=3
USERNAME_MAX_LENGTH=20
PASSWORD_MIN_LENGTH=10
PASSWORD_REQUIRE_COMPLEXITY=true
RATE_LIMIT_ATTEMPTS=5
RATE_LIMIT_WINDOW_SECONDS=600

# AD 目标
AD_DOMAIN=example.local
AD_UPN_SUFFIX=example.local
AD_BASE_DN=DC=example,DC=local
AD_USER_BASE_DN=CN=Users,DC=example,DC=local
AD_GROUP_CN=VDI_Users
AD_GROUP_DN=CN=VDI_Users,CN=Users,DC=example,DC=local
```

## 安全建议

- 只把 `public/` 暴露给 Web 服务
- 生产环境必须启用 HTTPS
- 公开访问场景建议保持 `INVITE_REQUIRED=true`
- 优先使用 `ADMIN_PASSWORD_HASH`，不要在 `.env` 中保存明文后台密码
- 使用专用 AD 服务账号，并只授予创建用户、设置密码、加组等必要权限
- 不要提交 `.env`、`.env.local`、SQLite 数据库、日志文件或真实凭据
- 定期备份 `storage/data/app.sqlite`
- 部署前先运行 `php bin/check_ad.php` 验证 AD 连接和默认组配置

## 许可证

本项目采用 **All Rights Reserved** 许可。详见 `LICENSE`。
