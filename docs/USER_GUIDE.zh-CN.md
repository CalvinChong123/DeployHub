# DeployHub 使用教程

适用于当前项目的页面与功能。首次使用顺序：登录 → 绑定验证器 → 注册 SSH 服务器 → 添加独立项目 → 检查 Git → 审阅并确认部署 → 查看结果。

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

如果项目和服务器已经配置好，想让 Codex 代为部署，可以在 **Account security → AI deployment access** 创建一个绑定指定部署目标的 token。创建时需要完成 MFA 并确认当前密码；以后 AI 使用该 token 调用，不需要每次打开网页确认或提供验证码。此功能支持所有已授权目标，包括 production；AI 确认审阅请求后即可进入部署队列，无需另一位成员审批。完整操作见本教程末尾的 AI 部署说明。

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
3. 尚未保存指纹时，默认提供 **Trust on first use**（首次信任）：确认页面显示的服务器地址、端口和指纹，勾选信任确认框，再点击 **Trust & save fingerprint**。此方式不要求向主机商索取指纹，但首次连接没有独立核实服务器身份。
4. 如果希望独立核实，选择 **Verify with a trusted source**，通过可信服务器控制台、已信任的 SSH 记录或主机商提供的信息核对指纹。核对一致后勾选确认框，点击 **Pin verified fingerprint**。
5. 点击 **Test authentication**，确认 SSH 登录成功。

保存后，每次连接都会核对指纹。已有指纹发生变化时，连接会被阻止，不能用“首次信任”直接覆盖；必须独立核实新的指纹。发现结果有效期为十分钟，服务器配置或已保存指纹改变后，需要重新点击 **Discover fingerprint**。首次信任和独立核对会留下不同的审计记录。

DeployHub 连接服务器使用的 SSH 私钥，与服务器访问 Git 仓库的凭据是两个用途。服务器上的部署用户还需要能够自行访问仓库 origin；私有仓库的权限应事先配置。

## 3. 添加独立部署项目

回到 Dashboard 或 Projects，点击 **Add project**。项目创建表单同时登记一个独立部署环境。

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

服务器目录中的 `git remote get-url origin` 可用于核对 Exact origin URL。HTTPS 与 SSH 地址即使指向同一个仓库，也不算完全一致。

**Deployment workflow** 直接分为 **Pre deploy / Post deploy**，内置选项和自定义 Hook 放在同一列表，用户自己勾选，并通过 ↑ / ↓ 调整同阶段顺序。

- **Hard reset tracked changes**：默认在 Pre，默认不勾选。执行 `git reset --hard HEAD`，丢弃已跟踪文件的本地改动，再更新到审阅的目标 commit。不会执行全面的 `git clean`；一般保留未跟踪文件，但挡住已跟踪路径的文件可能被 reset 删除。
- **Install Composer dependencies**：默认在 Post，安装 PHP 依赖，禁用 Composer scripts/plugins。
- **Clear Laravel caches**：默认在 Post；新项目表单默认勾选。
- **Optimize Laravel**、**Restart Laravel queues**：默认在 Post，按应用需要勾选。
- **HTTPS health check**：在 Post，默认排最后。勾选后填写不重定向、返回 2xx 的 HTTPS 地址。

每行只显示勾选、名称和排序。命令默认收起，需要查看时展开 **View command** 或 **Edit Shell script**。动作所在的 Pre/Post 分组就是执行阶段，无需再选择一次。

点击 **+ Add Pre hook / + Add Post hook** 可新增自定义脚本，编辑区会自动展开。填写名称与 Shell script，再勾选名称旁的启用框；新 Hook 默认关闭，可以移位或删除。内置选项可取消勾选，命令固定。已有项目在 **Pipeline & settings** 修改，保存后旧预览失效。最多 26 个条目（含六个内置选项）。普通 PHP 项目可取消不适用的 Laravel 选项。

执行顺序：审阅状态检查 → 已启用的 Pre 条目 → 重新检查仓库身份和起始/目标 commit → Git 更新 → 已启用的 Post 条目 → 最终 commit 验证。内置选项使用固定命令；自定义脚本以 deployment username 在项目根目录执行，各自使用独立 `sh -e` 会话，不共享 `export` / `cd`。某步失败就停止后续步骤，日志保留已执行结果。

**为什么以前提示 Working tree is dirty，但 terminal 的 git pull 没有报错？** 旧版 DeployHub 提前要求完全干净，比 Git 严格。现在允许无冲突的本地改动：如果远程提交没有覆盖你修改的 `.htaccess`，以及没有与 `error_log` 的路径冲突，这些文件保留，更新可以成功。若会覆盖本地改动，则由 Git 拒绝，在部署 console 查看具体文件。DeployHub 使用 fetch 后 `git merge --ff-only` 到审阅的确切 SHA；不会创建 merge commit，也不会自动采用审阅后新增的提交。

如果你希望每次舍弃 cPanel 的已跟踪改动，勾选 Pre 中的 **Hard reset tracked changes** 即可。需要额外删除指定日志时，再新增 Pre Hook：

```sh
rm -f -- public/error_log
```

也可把日志路径写入服务器的 `.git/info/exclude`。不必为无冲突日志强制清理。预检不运行 Hook；启用 Pre 时会冻结审阅的文件内容，若内容后来变化则需重新预览。不要在 Pre 中切换 HEAD 到其他 commit。保留本地改动时，即使 HEAD 正确，实际文件仍可能包含这些本地差异。

点击 **Register project**；无服务器时需先注册服务器。

### 复制与删除

每个项目对应一个独立部署环境。在详情中展开 **Repository details**，点击 **Copy project**，仓库、分支、服务器和 workflow 自动填入；修改项目名称、环境标签、目录或服务器后点击 **Create copied project**。目标目录必须事先准备好 Git；复制配置不会在服务器 clone 文件。新项目有自己的权限与历史，创建者自动获得访问权限，其他成员按需授权。

已有同一 Project 下的多个 Environment 会拆成独立项目，保留各环境配置、成员权限、部署 ID 与日志；名称会带上环境标签，便于区分。

**Project settings → Delete project** 仅移除当前项目登记，其他项目不受影响，远程文件不删除。历史仍可在 **Deployment activity** 查看。已占用目标的旧待审批请求、排队、执行或状态 Unknown 的部署需先完成、取消或核对状态；仓库操作进行中也不能删除。尚未占用目标的定时计划会随登记删除而失效，保留历史。删除后可重新登记同一服务器目录。修改 SSH server 时仍需确认向缺少新服务器权限的现有成员授权；成员原有权限保留。


## 4. 检查仓库，再审阅部署

项目注册成功后进入环境详情：

1. 点击 **Check repository**。系统连接服务器，检查路径、origin、分支、工作区和提交历史，并 fetch 配置的远程分支。这一步不会更新工作区代码。
2. 查看 **Incoming commits**。如果显示 **Already up to date**，表示当前代码已处于获取到的目标 commit。
3. 有代码更新或需要执行已启用的 Hook 时，点击 **Prepare deployment →**。刚才成功的预检在两分钟内、配置不变时直接复用，不会再次等待 SSH。无需每次先点击 Check repository；没有有效预检时会自动检查。有启用 Hook 时，即使代码已是最新也可执行。
4. 核对服务器环境、目录、分支、起始 commit、目标 commit、待发布提交、工作区改动和 Hook 脚本。脚本按 Pre/Post 分组并默认收起，点击名称展开查看。
5. 在 **Deployment timing** 默认选择 **Deploy now**；若希望定时部署，选择 **Schedule** 并填写日期时间。更改时间会取消审阅勾选，需要重新核对后勾选。
6. 勾选 **I reviewed the commit, target, enabled workflow, local changes and deployment timing.**。所有环境（包括 production）点击 **Deploy now** 或 **Schedule deployment**，无需独立审批。

项目列表显示最近一次已确认的部署结果或仍有效的历史待处理请求，以及上次检查的待发布数量。项目首页的 **Last successful deployment** 是历史成功记录；如果后来的部署更新了代码再失败，它不能代表现在运行的版本。**Incoming · last check** 和展开后的 **Last inspected HEAD / fetched origin** 都是上次检查结果。新项目未检查时显示 Not checked，可直接点击 Prepare deployment 自动检查。

审阅页在桌面左侧显示 commit、已启用 workflow 和待发布提交，右侧确认立即或定时部署；手机上纵向排列，顶部 **Review & confirm ↓** 可直接跳到确认区。展开 **Commit & path details** 核对完整 SHA、目录和历史审批信息，展开 **Full execution order** 查看所有步骤顺序。

预检已合并远程命令，普通检查只需要两次 SSH 命令调用；启用 Pre 时会额外批量核对本地文件内容。页面显示本次检查耗时。正式部署始终重新 fetch 并验证审阅的 commit，Pre Hook 后只重新检查仓库身份和 commit，不重复 fetch / 提交列表。真实耗时仍取决于主机登录与仓库 fetch。审阅页面固定本次发布的确切 commit，不会自动替换成后来推送的最新 commit。候选请求有一小时有效期；过期后重新创建预览。

预检失败时核对目录、origin、分支、仓库访问权限和快进历史。本地改动显示为 Local changes，本身不会阻止审阅；会覆盖文件的更新由 Git 拒绝。可保留不冲突的改动，或勾选 Hard reset / 配置 Pre Hook 处理。启用 Pre 后，若审阅的文件内容变化，执行停止并要求新预览。

## 5. 部署确认与成员权限

所有环境（包括 production）都由有 Deploy 权限的提交人审阅并确认部署。独立审批选项、审批权限和 Approvals 导航已废弃。Production 标记仅用于区分环境，不再要求另一位成员审批。

管理员可在 **Team & access** 创建成员。新成员也要登录并完成验证器绑定，再在对应环境的 **Access** 页面授予 View / Deploy 权限。Viewer 不能部署；管理员身份也需要具体环境授权。项目创建者自动获得新环境的权限。

提交人点击 **Deploy now** 后，即时部署进入队列；点击 **Schedule deployment** 后，定时部署等待已选时间。旧的 **Awaiting approval** 请求不会自动执行：由原提交人重新审阅并确认；已过期的请求需重新创建预览。旧审批记录作为历史保留，旧 Approvals 链接会跳转到过滤后的 Deployment activity。

项目详情的 **Deployments** 和 Dashboard 最近部署隐藏已过期的请求，包括尚未被后台标记为 Expired 的待确认／待审批请求，以及到点仍未由提交人确认的定时请求；项目的最近部署摘要也跳过这些请求。有效请求和实际执行结果仍可翻页查看。**Deployment activity** 保留完整的授权历史，可通过搜索和状态筛选追溯过期记录。

## 6. 让后台任务运行

### 预约一次定时部署

1. 打开项目详情，点击 **Prepare deployment →**，进入与立即部署共用的审阅页。
2. 核对确切 commit、配置与脚本，然后在 **Deployment timing** 选择 **Schedule**，日期时间输入框才会出现。
3. 选择未来的日期和时间，最多可提前 30 天。页面显示浏览器时区，例如 `Asia/Kuala_Lumpur`；后台按 UTC 保存，不会把本地时间当 UTC。
4. 核对显示的预约时间，勾选审阅确认，点击 **Schedule deployment**。生产环境使用同样的确认流程，无需独立审批。
5. 状态为 **Scheduled** 后等待到点。计划和预约时间显示在已有的 **Deployments** 历史及 **Deployment activity** 中；点击部署 ID 可查看或取消。没有独立的 Schedule 页面或模块。

选择时间不会再进行一轮 SSH 预检，仍使用刚才审阅的 commit 和脚本。后台保存包含时间的新审阅请求，原预览在历史中标记为 Cancelled；只有新请求等待执行，不会重复运行原预览。若改变主意，在提交前切回 **Deploy now** 即可，日期时间不会随立即部署提交。

这是一次性计划，发布现在审阅的 commit。等待期间不锁住项目；到点前若配置、凭据、权限、起始 commit 或分支目标改变，计划会停止并说明原因，需要重新审阅。不会自动采用后来推送的最新 commit。

调度器每分钟检查一次，所以可能比选择的时间晚几十秒；有其他部署占用目标时也会等待。已确认的计划允许在预约时间后的一小时内开始执行，包括本机短暂离线后恢复；超过一小时会过期。到点仍未确认的计划会过期。当前不支持每天重复的计划。

### 保持后台运行

本机需要保持 Laragon MySQL 运行。安装 Node.js 并执行 `npm ci` 后，可在 Git Bash 中运行 `./local-start.sh`，同时启动网站和部署队列 worker，按 Ctrl+C 会停止两者。定时部署仍需另开终端运行 `php artisan schedule:work`。

也可以在 `C:\laragon\www\deployhub` 分别打开三个终端：

```powershell
# 终端 1：网站
php artisan serve --host=127.0.0.1 --port=8018
```

```powershell
# 终端 2：部署任务处理
php artisan queue:work database --queue=default --sleep=2 --tries=1 --timeout=960
```

```powershell
# 终端 3：定时部署、检查与维护
php artisan schedule:work
```

如果网站已在运行，不需要重复启动。定时部署要求电脑保持开机、不休眠，并保持数据库、worker 和 scheduler 运行。选择 Schedule 时，如果没有近期调度器心跳，审阅页会显示离线或尚未确认运行的提示；刚启动时可等下一个分钟检查。

确认部署后一直显示 **Queued**，先检查队列 worker 是否开启。应用更新后需要重启手动启动的 worker 和 scheduler，让后台使用新代码。

## 7. 查看结果与常见卡点

部署详情中的 **Deployment progress** 展示仓库检查、Pre、Git 更新、Post 和最终验证的实际状态，以及各步骤耗时。未执行的阶段不会显示成功；正在运行的步骤会突出显示。成功输出默认收起，可点击 **View output**；失败或状态 Unknown 的输出默认展开。状态自动更新，部署结束后无需手动刷新页面；命令输出在该命令完成后显示。

| 现象 | 下一步 |
| --- | --- |
| Add project 跳到 Account security | 按第 1 节绑定验证器 |
| Register project 按钮禁用 | 按第 2 节注册服务器 |
| Test authentication 按钮禁用 | 先 Discover fingerprint，再选择首次信任或独立核对并保存 |
| SSH 登录失败 | 核对主机、端口、账号、私钥、公钥授权和指纹验证 |
| SSH 成功但 preflight 失败 | 核对 Git 目录、origin、分支、工作区和服务器访问仓库的权限 |
| Awaiting approval（旧记录） | 独立审批已废弃；原提交人重新审阅并确认，有效期已过则创建新预览 |
| Environment, credentials or pipeline changed | 曾有 MySQL JSON 字段顺序造成误判的问题，已修复。现在只有实际配置变化或完整性验证失败才拒绝；实际变化会列出字段，修改过配置时重新生成预览。 |
| Queued 长时间不动 | 检查本机数据库队列 worker |
| Scheduled 到点仍未执行 | 检查 scheduler 心跳、`schedule:work` 与 worker；其他部署可能占用目标。打开部署记录查看是否已过期或因配置、权限、仓库状态变化而失败。 |
| Failed | 查看失败步骤及之前已执行步骤，检查目标应用；系统不会自动回滚 |
| Unknown | 按 Operations 文档检查远程进程与应用状态，再进行管理员 reconciliation |

如果部署已更新代码，但后续流水线步骤失败，先检查已完成步骤并解决故障，再在失败部署详情点击 **Prepare recovery preview**。恢复请求会重新检查仓库，并要求当前 HEAD 和获取到的 origin 都等于失败部署的目标 commit；它不会执行 Git merge，而会从头重跑所选流水线步骤以及所有已启用的 Pre/Post Hook。需要提交人重新审阅与确认，生产环境也无需独立审批。若 origin 已前进，请审阅普通的新部署。Unknown 状态不能直接使用此恢复流程，系统也不会自动回滚代码或数据。

失去验证器时，本机管理员可以运行下面的恢复命令。该命令要求交互确认，会清除 MFA 设置并撤销该账号的数据库会话；再次登录后需要重新绑定验证器。

```powershell
php artisan deployhub:reset-mfa owner@deployhub.test --reason="Identity verified; authenticator lost"
```

更完整的安装、故障恢复与运维说明参见 [OPERATIONS.md](OPERATIONS.md)。

## 8. 让 Codex 代为部署

1. 登录 DeployHub 并完成验证器验证，在 **Account security** 点击 **AI deployment access**。
2. 明确勾选当前 workspace 内已授予你部署权限的 1–100 个目标，填写 token 名称、有效天数和当前登录密码，点击 **Create deployment token**。一个 token 可以授权多个项目，支持 staging、production 及其他已注册目标；默认不会勾选任何目标，任一选择无效会拒绝整个签发请求。
3. 创建成功后，页面会滚动到 **Copy your new token**，并聚焦 token 输入框。点击 **Copy token**，在离开或刷新页面前保存这段仅显示一次的值。DeployHub 不会自动将它写入文件、环境变量或 Codex 配置；授权记录只保存不可还原的 hash，明文仅临时经会话传给创建后的页面。若没有复制就离开或刷新，请在 **Your automation tokens** 撤销该 token 后重新创建。按页面的 **Connect Codex** 配置，将 token 保存在运行 Codex 的电脑上的 `DEPLOYHUB_AI_TOKEN` 环境变量中，再重启 Codex。不要把 token 发在聊天中或提交到 Git。Windows 的安全输入命令和完整配置见 [AI_DEPLOYMENTS.md](AI_DEPLOYMENTS.md)。
4. 在 Codex 中说：“检查并提交这些修改，push 到目标分支，然后部署这个 commit，完成后告诉我结果。”AI 会查询目标、预览完整 commit、调用确认并轮询结果，不要求你再打开网页确认普通目标。
5. 所有已授权目标（包括 production）在 AI 确认后进入队列。AI 继续查询状态，直到成功、失败或需要人工处理；Queued 不代表已经执行或成功。

代码必须先 commit 并 push；服务器获取到的目标分支尖端必须与 AI 提交的完整 commit SHA 一致。AI 不会把未提交的本地文件直接上传，也不会悄悄改成另一个最新 commit。**Queued** 只表示已入队，只有 **Succeeded** 才表示流水线完成；运行电脑上的 DeployHub 与 worker 必须保持启动。

在 **Your automation tokens** 可以查看签发时选择的目标并撤销整个 token。token 到期、撤销、修改密码、重设 MFA、用户停用或 workspace 成员资格失效时，所有目标都会停止接受此 token。某个目标失去部署权限、被归档，或其仓库、分支、目录、服务器或生产环境标记改变时，该目标会失效，其他仍有效的已选目标可继续使用。AI 查询目标时只会看到当前可用的目标；全部目标不可用时会拒绝该 token。worker 每一步都会复查；正在执行的单条命令会先结束，然后停止后续步骤，不会自动回滚。

一个 token 的目标白名单在签发时确定，新建项目不会自动加入。要扩大或更改范围，请重新完成 MFA 和密码确认后签发新 token。多个项目可共用一个 token 和一个 MCP 连接，也可按用途创建范围更小的 token，使用不同连接名称和环境变量。示例见 [多项目配置](AI_DEPLOYMENTS.md#multiple-projects)。每次部署都要明确选择对应项目和分支；预览、确认和查询结果应始终使用同一连接，新 token 不能读取旧 token 创建的部署记录。开发期统一采用新的目标数组与关联表模型，升级时先执行前向迁移并重新加载 worker；旧字段和旧签发请求格式已移除。

其他 AI 客户端需要支持 **Streamable HTTP** 和 **Bearer token** 认证。连接和密钥配置完成后，可将 [AI 客户端操作指南](MCP_AGENT_GUIDE.md) 交给 AI，按工具发现、目标核对、完整 SHA 预览、确认和状态查询的顺序操作。不同客户端的配置格式不同；当前服务没有 OAuth 授权流程，不能假设只支持 OAuth 的客户端可直接接入。

只检查连接时，调用 `list_deployment_targets` 即可，不会连接目标服务器。`prepare_deployment` 会通过 SSH 检查仓库并 fetch，创建预览。连接失败、token 失效、分支 SHA 不符和 worker 离线的处理方式见 [连接故障排查](AI_DEPLOYMENTS.md#connection-troubleshooting)。
