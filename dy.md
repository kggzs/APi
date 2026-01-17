# 📱 抖音无水印视频解析 API

## 📋 功能概述

`dy.php` 是一个功能完整的抖音视频解析工具，支持将抖音分享链接或视频ID解析为无水印的视频下载链接。该工具既可以作为API接口使用，也可以直接在浏览器中使用Web界面进行操作。

## ✨ 主要特性

- ✅ **多种输入方式**：支持抖音分享链接、短链接、长链接或纯数字视频ID
- ✅ **双重模式**：既可作为JSON API接口，也可作为Web界面使用
- ✅ **安全防护**：内置SSRF攻击防护、XSS防护、URL白名单验证
- ✅ **内容类型**：支持普通视频和图集两种内容类型
- ✅ **美观界面**：现代化的渐变UI设计，响应式布局，支持移动端
- ✅ **智能解析**：自动提取抖音链接，支持复制粘贴完整分享内容

## 🚀 使用方法

### 方式一：Web界面使用

1. 直接在浏览器中访问 `dy.php` 文件
2. 在输入框中粘贴抖音分享链接或口令
3. 点击"开始解析"按钮
4. 等待解析完成后，可下载视频或复制链接

### 方式二：API接口调用

#### 请求示例

**GET 请求：**
```
dy.php?api=1&url=https://v.douyin.com/xxxxx/
```

**POST 请求：**
```bash
curl -X POST "dy.php?api=1" \
  -d "url=https://v.douyin.com/xxxxx/"
```

#### 参数说明

| 参数名 | 类型 | 说明 | 示例 |
|--------|------|------|------|
| `api` | string | 必须，标识API调用模式 | `1` 或 `true` |
| `url` | string | 抖音分享链接或视频ID | `https://v.douyin.com/xxxxx/` 或 `7234567890123456789` |
| `msg` | string | 同 `url`，备用参数名 | 同上 |

#### 响应格式

**成功响应：**
```json
{
  "success": true,
  "author": "作者昵称",
  "title": "视频标题",
  "video_id": "7234567890123456789",
  "video_url": "http://www.iesdouyin.com/aweme/v1/play/?video_id=...",
  "play_url": "重定向后的真实播放链接",
  "cover": "封面图URL",
  "images": [],
  "type": "video",
  "timestamp": 1642560000
}
```

**图集响应：**
```json
{
  "success": true,
  "author": "作者昵称",
  "title": "图集标题",
  "video_id": "7234567890123456789",
  "video_url": null,
  "play_url": null,
  "cover": "封面图URL",
  "images": [
    "图片1URL",
    "图片2URL",
    "图片3URL"
  ],
  "type": "image",
  "timestamp": 1642560000
}
```

**错误响应：**
```json
{
  "success": false,
  "error": "错误信息描述",
  "code": 400
}
```

## 🔒 安全特性

### 1. SSRF 防护
- 严格的域名白名单验证（仅允许 `douyin.com`、`iesdouyin.com`、`v.douyin.com`）
- 内网IP检测和阻止
- 重定向URL二次验证

### 2. XSS 防护
- HTML转义（`htmlspecialchars`）
- JavaScript转义（`json_encode`）
- 前端双重验证

### 3. 输入验证
- 视频ID格式验证（10-20位数字）
- URL格式正则验证
- 输入清理和过滤

## 📁 文件结构

```
dy.php
├── DouyinParser 类
│   ├── parse()              # 主解析方法
│   ├── get_redirected_url() # 获取重定向URL
│   ├── get_video_info()     # 获取视频详细信息
│   ├── isSafeUrl()          # URL安全验证
│   ├── escapeHtml()         # HTML转义
│   └── escapeJs()           # JS转义
├── API 模式处理
│   └── JSON 响应输出
└── Web 界面
    ├── 输入表单
    ├── 解析结果展示
    ├── 视频预览
    ├── 图集网格展示
    └── 下载功能
```

## 🎨 Web界面功能

### 主要组件

1. **输入区域**
   - 支持粘贴完整分享内容
   - 自动提取抖音链接
   - 支持回车键快速解析

2. **解析结果展示**
   - 作者信息
   - 视频标题
   - 视频ID
   - 封面预览

3. **下载功能**
   - 视频：一键下载按钮 + 复制链接按钮
   - 图集：网格展示 + 单张下载 + 批量下载提示

4. **加载状态**
   - 旋转加载动画
   - 友好的错误提示

## ⚙️ 技术实现

### 核心流程

1. **URL 提取**：从用户输入中智能提取抖音链接或视频ID
2. **安全验证**：验证URL是否为抖音官方域名
3. **获取重定向**：跟随短链接获取最终URL
4. **提取视频ID**：从URL中提取视频ID（10-20位数字）
5. **获取视频信息**：请求抖音页面，解析 `RENDER_DATA` 或 `_ROUTER_DATA`
6. **数据提取**：从JSON数据中提取视频/图集信息
7. **返回结果**：格式化并返回解析结果

### 依赖要求

- **PHP 版本**：PHP 7.0+ （推荐 PHP 7.4+）
- **扩展要求**：
  - `curl` 扩展（必需）
  - `json` 扩展（必需，PHP 7.0+ 默认内置）
  - `mbstring` 扩展（推荐）

## 📝 注意事项

1. **API 限制**：免费使用，但建议控制请求频率，避免被抖音限制
2. **链接有效期**：解析出的下载链接可能存在时效性
3. **图集下载**：由于浏览器限制，批量下载需要手动逐一点击
4. **网络要求**：需要服务器能访问 `iesdouyin.com` 和 `douyin.com`
5. **SSL 验证**：当前代码禁用了SSL验证，生产环境建议启用

## 🔧 配置说明

### 域名白名单

如需添加其他域名，修改 `$allowedDomains` 数组：

```php
private $allowedDomains = [
    'douyin.com',
    'iesdouyin.com',
    'v.douyin.com',
    'www.douyin.com',
    'www.iesdouyin.com'
];
```

### User-Agent 自定义

修改请求头中的 User-Agent：

```php
private $headers = [
    'User-Agent: Mozilla/5.0 ...',
    'Referer: https://www.douyin.com/'
];
```

## 🐛 常见问题

**Q: 解析失败，提示"无法获取视频信息"**  
A: 可能是抖音页面结构变化，需要更新解析逻辑；或网络连接问题。

**Q: 下载链接无法访问**  
A: 抖音链接可能有时效性，建议解析后尽快下载。

**Q: API 调用返回空结果**  
A: 检查是否传入了 `api=1` 参数，确认URL格式正确。

**Q: 图集图片无法显示**  
A: 可能是图片链接失效或跨域问题，检查网络连接。

## 📄 许可证

本工具仅供学习交流使用，请遵守相关法律法规和抖音服务条款。

## 🔗 相关链接

- [抖音官网](https://www.douyin.com/)
- [抖音开放平台](https://open.douyin.com/)
