# 在线考试题库系统（876）

## 项目类型
- 全栈 Web 项目（`frontend` + `backend`）

## 项目简介
本项目是一个基于 Vue 3 + Laravel 12 的在线考试与题库管理系统，支持多角色登录、题库管理、试卷管理、在线考试与成绩统计。

## 技术栈
### 前端
- Vue 3
- Vite
- Pinia
- Vue Router
- Axios
- TailwindCSS

### 后端
- Laravel 12（PHP 8.2）
- Laravel Sanctum（Token 鉴权）
- MySQL 8.0

### 运行方式
- Docker Compose（推荐，当前项目默认方式）

## 目录结构
```text
876/
├── docker-compose.yml
├── README.md
├── frontend/
│   ├── Dockerfile
│   ├── nginx.conf
│   ├── package.json
│   └── src/
├── backend/
│   ├── Dockerfile
│   ├── composer.json
│   ├── app/
│   └── routes/
├── docs/
│   ├── ARCHITECTURE.md
│   └── Database.sql
├── scripts/
└── evidence/
```

说明：`node_modules/`、`vendor/` 等依赖目录由 Docker 构建时自动安装，不需要打包提交。

## 启动与重建
在仓库根目录执行：

```bash
docker compose down
docker compose up -d --build
docker compose ps
```

## 服务地址
| 服务 | 地址 | 说明 |
|---|---|---|
| 前端 | http://localhost:8080 | 用户界面 |
| 后端 API | http://localhost:9000/api | Laravel API |
| MySQL | localhost:3307 | 数据库端口映射 |

## 测试账号
| 角色 | 邮箱 | 密码 |
|------|-------|----------|
| Admin | admin@example.com | password |
| Teacher | teacher@example.com | password |
| Student | student1@example.com | password |

> 登录页已移除快捷测试账号模块，请手动输入账号密码。

## README 与测试账号清单同步（必跑）
在截图前、提交前执行以下命令：

```bash
node scripts/sync-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
node scripts/verify-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
```

阻断规则：任一命令失败都应视为 `README_TEST_CREDENTIALS_MISMATCH`，不得继续提交流程。

## 核心功能
1. 用户认证：注册、登录、退出。
2. 题库管理：题目增删改查、分类管理。
3. 试卷管理：试卷创建、编辑、题目关联。
4. 在线考试：开始考试、提交答卷、自动评分。
5. 成绩统计：个人成绩与管理端统计数据。
6. 监考回放：考试中自动采集切屏/窗口失焦、摄像头断开与恢复、长时间无操作、网络中断与恢复、退出全屏等事件，按发生时间形成时间线。
7. 异常申诉：学生可对某条异常（或整场考试）填写说明并上传 1-3 张截图。
8. 教师复核：教师在"监考复核台"查看回放与证据，可驳回，或判定申诉成立时撤销异常标记并输入分数调整（可加可减）；分数、异常标记与成绩统计实时联动更新。

### 监考与申诉接口
| 方法 | 路径 | 角色 | 说明 |
|---|---|---|---|
| POST | `/api/proctoring/events` | 学生 | 考试中批量上报监考事件（定时上报 + 交卷/关闭页面补发） |
| GET | `/api/proctoring/records/{record}/timeline` | 本人 | 监考回放时间线 |
| GET | `/api/proctoring/records` | 教师/管理员 | 监考记录列表（支持按试卷/异常/待申诉筛选） |
| GET | `/api/proctoring/records/{record}/review` | 教师/管理员 | 任意考试的回放时间线 |
| POST | `/api/appeals` | 学生 | 提交申诉（multipart，说明 + 截图） |
| GET | `/api/appeals/mine` | 学生 | 我的申诉 |
| GET | `/api/appeals` | 教师/管理员 | 申诉处理列表 |
| POST | `/api/appeals/{appeal}/review` | 教师/管理员 | 复核：`approved`（改判+撤销标记）/ `rejected` |
| GET | `/api/proctoring/evidence/{id}?token=` | 本人/教师 | 鉴权读取申诉截图 |

> 复核改判在数据库事务内完成：更新 `exam_records.score`、把对应 `proctoring_events.is_waived` 置 1、写入复核结论；统计接口基于这两张表实时聚合，无需额外刷新。


## 角色权限
| 角色 | 可访问模块 |
|---|---|
| Student | 在线考试、我的成绩 |
| Teacher | 在线考试、我的成绩、题库管理、试卷管理 |
| Admin | 全部功能（含数据统计） |

## 人工验证步骤（建议）
1. 打开登录页：`http://localhost:8080/login`。
2. 使用测试账号手动登录，确认菜单与角色权限一致。
3. 进入题库管理，验证新增/编辑/删除流程。
4. 进入试卷管理，验证题目关联与试卷删除流程。
5. 学生账号完成一次在线考试并查看成绩。
6. Admin 查看统计页数据。
7. API 冒烟：

```bash
docker compose exec backend sh -lc "curl -s -o /tmp/unauth.txt -w '%{http_code}\n' http://localhost:8080/api/exams"
docker compose exec backend sh -lc "curl -s -X POST http://localhost:8080/api/auth/login -H 'Content-Type: application/json' -d '{\"email\":\"admin@example.com\",\"password\":\"password\"}'"
```

预期：未登录访问受保护接口返回 `401`；登录接口返回包含 `token` 的 JSON。

## 安全与质量说明
- 密码为哈希存储（bcrypt）。
- API 使用 Sanctum Token 鉴权。
- 接口包含输入校验与错误处理。
- CORS 与基础限流已配置。

## 数据库说明
当前初始化后包含 13 张核心表（含用户、题目、试卷、考试记录、答案记录、监考事件、申诉、申诉证据等）。

详见：
- `docs/Database.sql`
- `docker-compose.yml` 中 `db-init` 初始化段

## 证据目录
测试与质检证据统一放在 `evidence/`（含 `evidence/run-slot*/`）目录。

---
如需进行质检修复闭环，请配合 `qa/qc-feedback-inbox.md`、`qa/qc-fix-send-template.md`、`qa/qc-fix-loop-template.md` 使用。

