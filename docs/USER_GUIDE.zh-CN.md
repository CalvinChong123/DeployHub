# DeployHub 使用教程

适用于当前项目的页面与功能。首次使用顺序：登录 → 绑定验证器 → 注册 SSH 服务器 → 添加项目与环境 → 检查 Git → 审阅并确认部署 → 查看结果。

## 1. 点击 Add project 后为什么出现 Account security？

这是首次使用的正常流程。添加项目、管理服务器、运行部署都需要先启用双因素验证（MFA）。登录密码之外，你还需要验证器生成的六位动态验证码。

在 Account security 页面操作：

1. 在 **Confirm your password** 输入当前的 DeployHub 登录密码。
2. 点击 **Set up authenticator**。
3. 页面会显示绑定二维码。
4. 打开你使用的、支持六位 TOTP 验证码的验证器应用，新增账号并选择扫描二维码。不同应用的菜单名称可能不同。
5. 扫描网页上的二维码，验证器中会添加 `DeployHub` 和你的登录邮箱对应的账号。无法扫码时，展开 **Can't scan? Enter the setup key manually**，手动输入 setup key，类型选择 **基于时间 / Time-based / TOTP**，使用六位验证码。
6. 验证器会生成六位数字。在网页的 **Six-digit code** 输入当前数字，点击 **Verify & enable**。
7. 成功后自动回到 Dashboard。Security 页面会显示 **Enabled**。以后重新登录时也需要动态验证码。

二维码在浏览器本地生成，只在绑定过程中显示；绑定成功后不再显示二维码和 setup key。请妥善保存验证器的备份，不要分享绑定二维码或密钥。

如果验证码无效，检查手机和电脑是否使用自动校时，并尝试下一轮新验证码。系统拒绝重复使用已验证的验证码。

右上角 **Edit profile & password** 可以修改初始密码。如果已经改过密码，上述确认步骤应填写新密码。

## 2. 先注册一台 SSH 服务器

DeployHub 管理服务器上已经存在的 Git 项目。添加项目之前，需要有一台可通过 SSH 访问的部署服务器，并在其目标目录中准备好 Git checkout、应用配置及所需运行环境。

当前部署命令面向 Linux/POSIX 路径，例如 `/home/deploy/my-app`。Windows 上的 `C:\laragon\www\my-app` 不能直接作为远程部署目录填写。

进入侧栏 **Servers**，点击 **Register server** 或 **Register SSH server**，填写：

| 字段 | 填写内容 |
| --- | --- |
| Server label | 自己容易识别的名称，例如 `Staging VPS` |
| Hostname or IP | 部署服务器域名或 IP |
| SSH port | 服务器的 SSH 端口，默认 `22` |
| Deployment username | SSH 登录用户，例如 `deploy` |
| SSH private key | 专用于部署的 SSH 私钥完整内容；对应公钥应已加入该用户的授权文件 |
| Key passphrase | 私钥的加密口令；没有则留空 |

点击 **Save encrypted credential** 后：

1. 在服务器列表点击 **Manage →**。
2. 点击 **Discover fingerprint**，获取 SSH 服务器指纹。
3. 通过服务器控制台、托管商或可信管理员提供的信息独立核对指纹。
4. 核对一致后，勾选已比较可信来源的确认框，点击 **Pin verified fingerprint**。
5. 点击 **Test authentication**，确认 SSH 登录成功。

DeployHub 连接服务器使用的 SSH 私钥，与服务器访问 Git 仓库的凭据是两个用途。服务器上的部署用户还需要能够自行访问仓库 origin；私有仓库的权限应事先配置。

## 3. 添加项目和第一个环境

回到 Dashboard 或 Projects，点击 **Add project**。项目创建表单同时创建第一个部署环境。

下面是示例，请替换成你自己的实际配置：

| 字段 | 示例与含义 |
| --- | --- |
| Project name | `My Laravel App`，显示名称 |
| Exact origin URL | `git@github.com:your-team/my-app.git`；必须与服务器目录的 origin URL 完全一致 |
| Description | 可选说明 |
| Environment name | `Staging`，测试环境名称 |
| SSH server | 选择刚才注册并验证的服务器 |
| Absolute remote repository directory | `/home/deploy/my-app`，服务器上已有 Git 仓库的根目录 |
| Fixed Git branch | `staging` 或你实际部署的分支；服务器 checkout 应处于该分支 |
| This is a production environment | 仅实际生产环境勾选 |
| Require independent approval | 勾选后需要其他有权限的成员审批；生产环境始终要求审批 |

服务器目录中的 `git remote get-url origin` 可用于核对 Exact origin URL。HTTPS 与 SSH 地址即使指向同一个仓库，也不算完全一致。

**Approved pipeline** 中，Git 检查、快进更新和最终 commit 验证始终执行。其他步骤按目标项目需要选择：

- **Install Composer dependencies**：安装 PHP 依赖，禁用 Composer scripts/plugins。
- **Clear Laravel caches**：执行 Laravel 缓存清理；这是新表单默认选中的步骤。
- **Optimize Laravel**：生成 Laravel 优化缓存。
- **Restart Laravel queues**：向目标 Laravel 队列发出重启信号。
- **HTTPS health check**：填写可返回 2xx 且不重定向的 HTTPS 地址，例如 `https://staging.example.com/up`。

选中的步骤全部为必需步骤。普通 PHP 项目应取消不适用的 Laravel 步骤。当前流水线不会自动执行前端构建、数据库迁移或首次安装；这些准备应按项目要求自行完成。

点击 **Register project**。如果没有服务器，按钮会被禁用，页面会提示先注册服务器。

同一项目中的环境名称必须唯一。修改环境的 SSH server 时，页面会列出尚未获得新服务器权限的现有成员；必须勾选确认授权后才能保存。成员原有的环境权限保留，Viewer 不会获得部署或审批权限。

## 4. 检查仓库，再审阅部署

项目注册成功后进入环境详情：

1. 点击 **Refresh preflight**。系统连接服务器，检查路径、origin、分支、工作区和提交历史，并 fetch 配置的远程分支。这一步不会更新工作区代码。
2. 查看 **Incoming commits**。如果显示 **Already up to date**，表示当前代码已处于获取到的目标 commit。
3. 有代码更新需要发布时，点击 **Review deployment →**。
4. 核对服务器环境、目录、分支、起始 commit、目标 commit、待发布提交和计划步骤。
5. 勾选 **I reviewed the exact commit, environment and approved pipeline.**。
6. 无审批要求的测试环境点击 **Confirm staging deployment**；需要审批的环境点击 **Request independent approval**。

审阅页面固定本次发布的确切 commit，不会自动替换成后来推送的最新 commit。候选请求有一小时有效期；过期后重新创建预览。

检查失败时，按错误提示处理服务器上的仓库状态。常见原因包括目录错误、origin 不一致、分支不符、存在未提交文件或无法快进更新。DeployHub 不提供强制覆盖或自动清理操作。

## 5. 生产审批与成员权限

生产环境必须由另一位成员审批，发起人不能审批自己的请求。

管理员可在 **Team & access** 创建成员。新成员也要登录并完成验证器绑定，再在对应环境的 **Access** 页面授予 View / Deploy / Approve 权限。Viewer 不能部署或审批；管理员身份也需要具体环境授权。项目创建者自动获得新环境的权限。

审批人审阅候选请求并点击 **Approve exact candidate** 后，部署才会进入队列。

**Approvals** 仅显示尚未过期的待审批请求，并提供分页。**Deployment activity** 的搜索和状态筛选会查询完整的授权历史；环境详情中的 **Deployments** 也可翻页查看更早记录。

## 6. 让后台任务运行

本机需要保持 Laragon MySQL 运行。在 `C:\laragon\www\deployhub` 分别打开三个终端：

```powershell
# 终端 1：网站
php artisan serve --host=127.0.0.1 --port=8018
```

```powershell
# 终端 2：部署任务处理
php artisan queue:work database --queue=default --sleep=2 --tries=1 --timeout=960
```

```powershell
# 终端 3：定时检查与维护
php artisan schedule:work
```

如果网站已在运行，不需要重复启动。确认部署后一直显示 **Queued**，先检查队列 worker 是否开启。

## 7. 查看结果与常见卡点

部署详情中的 **Deployment console** 展示各步骤状态和输出。状态自动更新；命令输出在该命令完成后显示。

| 现象 | 下一步 |
| --- | --- |
| Add project 跳到 Account security | 按第 1 节绑定验证器 |
| Register project 按钮禁用 | 按第 2 节注册服务器 |
| SSH 登录失败 | 核对主机、端口、账号、私钥、公钥授权和指纹验证 |
| SSH 成功但 preflight 失败 | 核对 Git 目录、origin、分支、工作区和服务器访问仓库的权限 |
| Awaiting approval | 由另一位有该环境审批权限且已启用 MFA 的成员审阅 |
| Queued 长时间不动 | 检查本机数据库队列 worker |
| Failed | 查看失败步骤及之前已执行步骤，检查目标应用；系统不会自动回滚 |
| Unknown | 按 Operations 文档检查远程进程与应用状态，再进行管理员 reconciliation |

如果部署已更新代码，但后续流水线步骤失败，先检查已完成步骤并解决故障，再在失败部署详情点击 **Prepare recovery preview**。恢复请求会重新检查仓库，并要求当前 HEAD 和获取到的 origin 都等于失败部署的目标 commit；它不会执行 Git 更新，而会从头重跑所选流水线步骤。需要重新审阅与确认，生产或受保护环境还需要另一位成员重新审批。若 origin 已前进，请审阅普通的新部署。Unknown 状态不能直接使用此恢复流程，系统也不会自动回滚代码或数据。

失去验证器时，本机管理员可以运行下面的恢复命令。该命令要求交互确认，会清除 MFA 设置并撤销该账号的数据库会话；再次登录后需要重新绑定验证器。

```powershell
php artisan deployhub:reset-mfa owner@deployhub.test --reason="Identity verified; authenticator lost"
```

更完整的安装、故障恢复与运维说明参见 [OPERATIONS.md](OPERATIONS.md)。
