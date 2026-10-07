# 天方插件商店

这里登记天方签到平台插件商店中经过审核的插件版本。商店目录发布在 `gh-pages` 分支，站点通过 `https://longfie.github.io/tfsign-plugins/index.json` 读取。

`main` 分支的 `registry.json` 是唯一的发布依据：只有登记在这里、经维护者合并的版本才会被构建、签名并上架。插件仓库不需要配置任何令牌，也不需要发布工作流。

## 上架新插件

1. 插件源码放在**公开**的 GitHub 仓库，`plugin.json` 声明 `runtime.mode=runner`，并用 `network_hosts` 列出会访问的域名，例如：

   ```json
   "network_hosts": ["api.example.com", "*.cdn.example.com"]
   ```

   引入第三方 Composer 依赖时必须提交 `composer.lock`。商店构建时会以 `--no-scripts --no-plugins` 安装依赖，不会执行任何 Composer 脚本。

   网络请求、并发、等待和心跳任务统一使用平台运行时（实现 `RuntimeAwarePluginInterface` 并 `use UsesPluginRuntime`，见平台文档“平台运行时”一节）。构建时会扫描插件源码和依赖，直接调用 `curl_*`、`socket_*`、`fsockopen`、`stream_socket_*`、`sleep`、`usleep` 的版本无法发布。

2. Fork 本仓库，在 `registry.json` 的 `plugins` 中追加一条：

   ```json
   {
       "code": "myplugin",
       "repository": "your-name/tfsign-plugin-myplugin",
       "path": ".",
       "maintainers": ["your-name"],
       "tier": "community",
       "risk_labels": ["credentials"],
       "releases": [
           {"version": "1.0.0", "commit": "40 位提交 SHA"}
       ]
   }
   ```

3. 向 `main` 分支提 PR，按模板勾选上架规则。自动检查会读取该提交的 `plugin.json`、列出凭据字段和声明的域名，并提示源码中出现但未声明的域名。

4. 维护者审核通过并合并后，会立即触发平台仓库构建、签名并上架，通常几分钟内完成，进度可在本仓库 Actions 的「触发上架」中查看。

## 发布新版本

在自己的仓库提交代码并更新 `plugin.json` 的 `version` 与 `changelog`，然后提 PR，在对应插件的 `releases` 末尾追加：

```json
{"version": "1.1.0", "commit": "40 位提交 SHA"}
```

版本锁定到提交 SHA，之后移动标签或改写分支都不会影响已审核的内容。已审核版本不能修改或删除，新版本号必须大于已登记的版本。自动检查会附上与上个版本的源码差异链接，方便审核。

## 字段说明

| 字段 | 说明 | 谁可以修改 |
| --- | --- | --- |
| `code` | 插件代号，与 `plugin.json` 一致 | 首次登记时填写 |
| `repository`、`path` | 源码仓库与插件目录 | 商店维护者 |
| `maintainers` | 可以为该插件提交新版本的 GitHub 用户 | 商店维护者 |
| `tier` | `official`（官方）或 `community`（社区），新插件只能是 `community` | 商店维护者 |
| `risk_labels` | `credentials`（保存账号凭据）、`account_risk`（可能导致第三方账号受限）、`unofficial_api`（使用非公开接口） | 首次登记时可申报，之后由商店维护者调整 |
| `releases[].revoked` | 撤回原因；撤回后站点不能再安装该版本，已安装的站点会收到提示 | 商店维护者 |

## 撤回与申诉

发现插件存在安全问题或违反[上架规则](POLICY.md)时，维护者会在对应版本加上 `revoked` 原因。开发者可通过 Issue 说明情况并提交修复版本。
