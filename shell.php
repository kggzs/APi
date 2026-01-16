<?php
/**
 * 恶搞IP记录脚本（分层归档版）
 * 功能：1. 按日期生成简易信息log；2. ip/年月日/ 文件夹存储IP命名的详细信息文件；3. 兼容PHP 7.x
 */

// 步骤1：创建根级ip文件夹（统一存储所有归档文件）
$rootIpFolder = 'ip';
if (!is_dir($rootIpFolder)) {
    mkdir($rootIpFolder, 0755, true);
    @chmod($rootIpFolder, 0755);
}

// 步骤2：定义日期相关变量（用于分层归档）
$accessTime = date('Y-m-d H:i:s'); // 完整访问时间
$accessDate = date('Y-m-d'); // 年月日（简易log文件名、子文件夹名）
$dateFolderName = $accessDate; // 当日子文件夹名（格式：2026-01-16）
$dateFolderPath = "{$rootIpFolder}/{$dateFolderName}"; // 当日子文件夹完整路径：ip/2026-01-16

// 步骤3：创建当日日期子文件夹（存放IP命名的详细信息文件）
if (!is_dir($dateFolderPath)) {
    mkdir($dateFolderPath, 0755, true);
    @chmod($dateFolderPath, 0755);
}

// 步骤4：获取访问者的真实IP地址（兼容代理环境）
function getClientRealIp() {
    $ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        $ip = explode(',', $ip)[0];
        $ip = trim($ip);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ?: '127.0.0.1';
}

// 步骤5：获取IP粗略定位（调用免费公开API，无需申请密钥）
function getIpLocation($ip) {
    if (in_array($ip, ['127.0.0.1', '::1'])) {
        return '本地主机 - 内网环境（已锁定设备MAC地址）';
    }
    $apiUrl = "http://ip-api.com/json/{$ip}?lang=zh-CN";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || empty($response)) {
        return '定位失败 - 已启动备用卫星定位系统追踪';
    }
    $result = json_decode($response, true);
    if ($result['status'] !== 'success') {
        return '定位失败 - 已触发全网IP追踪机制';
    }
    return sprintf(
        '%s - %s %s %s - %s（精准定位误差≤50米，已关联所在区域监控）',
        $result['country'],
        $result['regionName'],
        $result['city'],
        $result['zip'],
        $result['isp']
    );
}

// 步骤6：采集浏览器/客户端完整详细信息
function collectBrowserAllInfo() {
    $browserInfo = [];
    
    // 一、HTTP请求头信息
    $browserInfo['HTTP请求头信息'] = [
        '用户代理（User-Agent）' => $_SERVER['HTTP_USER_AGENT'] ?? '未知',
        '接受的内容类型' => $_SERVER['HTTP_ACCEPT'] ?? '未知',
        '接受的编码格式' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '未知',
        '接受的语言' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '未知',
        '请求来源页面' => $_SERVER['HTTP_REFERER'] ?? '直接访问/无来源',
        '连接方式' => $_SERVER['HTTP_CONNECTION'] ?? '未知',
        '主机地址' => $_SERVER['HTTP_HOST'] ?? '未知',
        'Cookie信息' => $_COOKIE ? json_encode($_COOKIE, JSON_UNESCAPED_UNICODE) : '无Cookie'
    ];
    
    // 二、客户端环境信息
    $browserInfo['客户端环境信息'] = [
        '请求方法' => $_SERVER['REQUEST_METHOD'] ?? '未知',
        'PHP_SELF' => $_SERVER['PHP_SELF'] ?? '未知',
        '查询字符串' => $_SERVER['QUERY_STRING'] ?? '无查询参数',
        '服务器端口' => $_SERVER['SERVER_PORT'] ?? '未知',
        '服务器软件' => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
        '网关接口' => $_SERVER['GATEWAY_INTERFACE'] ?? '未知',
        '远程端口' => $_SERVER['REMOTE_PORT'] ?? '未知'
    ];
    
    // 三、浏览器解析信息
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $browserInfo['浏览器解析信息'] = [
        '浏览器类型' => getBrowserType($userAgent),
        '操作系统类型' => getOsType($userAgent),
        '设备类型' => getDeviceType($userAgent)
    ];
    
    return $browserInfo;
}

// 辅助函数：解析User-Agent（兼容PHP 7.x，使用strpos）
function getBrowserType($userAgent) {
    if (strpos($userAgent, 'Chrome') !== false) {
        return 'Google Chrome 浏览器';
    } elseif (strpos($userAgent, 'Firefox') !== false) {
        return 'Mozilla Firefox 浏览器';
    } elseif (strpos($userAgent, 'Safari') !== false && strpos($userAgent, 'Chrome') === false) {
        return 'Apple Safari 浏览器';
    } elseif (strpos($userAgent, 'Edge') !== false) {
        return 'Microsoft Edge 浏览器';
    } elseif (strpos($userAgent, 'IE') !== false || strpos($userAgent, 'Trident') !== false) {
        return 'Microsoft Internet Explorer 浏览器';
    } else {
        return '未知浏览器/爬虫程序';
    }
}

function getOsType($userAgent) {
    if (strpos($userAgent, 'Windows') !== false) {
        return 'Windows 操作系统';
    } elseif (strpos($userAgent, 'Mac OS') !== false) {
        return 'Mac OS 操作系统';
    } elseif (strpos($userAgent, 'Linux') !== false) {
        return 'Linux 操作系统';
    } elseif (strpos($userAgent, 'Android') !== false) {
        return 'Android 移动操作系统';
    } elseif (strpos($userAgent, 'iOS') !== false || strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false) {
        return 'iOS 移动操作系统';
    } else {
        return '未知操作系统';
    }
}

function getDeviceType($userAgent) {
    if (strpos($userAgent, 'Mobile') !== false || strpos($userAgent, 'Android') !== false || strpos($userAgent, 'iPhone') !== false) {
        return '移动设备（手机/平板）';
    } elseif (strpos($userAgent, 'iPad') !== false) {
        return '平板设备';
    } else {
        return '桌面设备（电脑）';
    }
}

// 步骤7：核心逻辑执行（获取所有信息）
$clientIp = getClientRealIp();
$ipLocation = getIpLocation($clientIp);
$browserAllInfo = collectBrowserAllInfo();

// 步骤8：拼接两类日志内容（简易信息 + 详细信息）
// 8.1 简易信息（用于日期log文件，简洁明了）
$simpleLogContent = "访问时间：{$accessTime} | IP地址：{$clientIp} | IP定位：{$ipLocation}" . PHP_EOL;

// 8.2 详细信息（用于IP命名文件，完整归档所有采集内容）
$detailedLogContent = "=== 详细访问记录（IP：{$clientIp}）===\n";
$detailedLogContent .= "创建时间：{$accessTime}\n";
$detailedLogContent .= "IP地址：{$clientIp}\n";
$detailedLogContent .= "IP定位：{$ipLocation}\n\n";

foreach ($browserAllInfo as $infoType => $infoDetails) {
    $detailedLogContent .= "【{$infoType}】\n";
    foreach ($infoDetails as $infoKey => $infoValue) {
        $detailedLogContent .= "  - {$infoKey}：{$infoValue}\n";
    }
    $detailedLogContent .= "\n";
}
$detailedLogContent .= "=== 记录结束 ===\n";

// 步骤9：写入简易信息（按日期生成log文件，存放于ip根文件夹）
$simpleLogFileName = "{$rootIpFolder}/access_simple_{$accessDate}.txt";

// 当日简易log文件不存在则创建并写入头部
if (!file_exists($simpleLogFileName)) {
    $createSimpleFile = @fopen($simpleLogFileName, 'w');
    if ($createSimpleFile) {
        $simpleFileHeader = "=== 每日访问简易日志 ===\n创建日期：{$accessDate}\n创建时间：{$accessTime}\n日志说明：记录当日访问者核心简易信息\n\n";
        fwrite($createSimpleFile, $simpleFileHeader);
        fclose($createSimpleFile);
        @chmod($simpleLogFileName, 0644);
    }
}
// 追加写入当日简易记录
@file_put_contents($simpleLogFileName, $simpleLogContent, FILE_APPEND | LOCK_EX);

// 步骤10：写入详细信息（以IP为文件名，存放于当日日期子文件夹）
$detailedFileName = "{$dateFolderPath}/{$clientIp}.txt"; // 路径：ip/2026-01-16/127.0.0.1.txt

// 若该IP当日首次访问则创建新文件，重复访问则追加记录（避免覆盖）
@file_put_contents($detailedFileName, $detailedLogContent . "\n\n", FILE_APPEND | LOCK_EX);
@chmod($detailedFileName, 0644);

// 步骤11：超强恐吓提示页面（保留原有视觉威慑效果）
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>⚠️ 非法访问 - 系统已报警 ⚠️</title>
    <style>
        body {
            background-color: #000;
            font-family: "Microsoft YaHei", Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            animation: bgFlicker 0.5s infinite alternate;
        }
        @keyframes bgFlicker {
            from { background-color: #000; }
            to { background-color: #2b0000; }
        }
        .warning-box {
            background-color: #1a0000;
            border: 3px solid #ff0000;
            border-radius: 5px;
            padding: 50px;
            max-width: 700px;
            width: 100%;
            box-shadow: 0 0 30px rgba(255, 0, 0, 0.7), 0 0 60px rgba(255, 0, 0, 0.4);
            animation: borderPulse 1s infinite alternate;
        }
        @keyframes borderPulse {
            from { border-color: #ff0000; box-shadow: 0 0 30px rgba(255, 0, 0, 0.7), 0 0 60px rgba(255, 0, 0, 0.4); }
            to { border-color: #ff6666; box-shadow: 0 0 40px rgba(255, 0, 0, 0.9), 0 0 80px rgba(255, 0, 0, 0.6); }
        }
        .warning-title {
            color: #ff0000;
            font-size: 32px;
            text-align: center;
            margin-bottom: 40px;
            border-bottom: 2px solid #ff3333;
            padding-bottom: 25px;
            animation: textFlicker 0.3s infinite alternate;
        }
        @keyframes textFlicker {
            from { color: #ff0000; }
            to { color: #ff9999; }
        }
        .info-item {
            font-size: 18px;
            margin: 20px 0;
            line-height: 2;
            color: #fff;
        }
        .info-label {
            font-weight: bold;
            color: #ff4444;
            display: inline-block;
            width: 160px;
            text-shadow: 0 0 5px #ff0000;
        }
        .info-value {
            color: #ffcccc;
            text-shadow: 0 0 3px #ff3333;
        }
        .danger-tip {
            color: #ff0000;
            font-weight: bold;
            font-size: 20px;
            text-align: center;
            margin: 30px 0;
            line-height: 2.2;
            text-shadow: 0 0 10px #ff0000;
            animation: textShake 0.8s infinite alternate;
        }
        @keyframes textShake {
            from { transform: translateX(-2px); }
            to { transform: translateX(2px); }
        }
        .footer-alert {
            margin-top: 40px;
            text-align: center;
            color: #ff6666;
            font-size: 16px;
            font-style: italic;
            border-top: 1px solid #330000;
            padding-top: 20px;
        }
        .danger-icon {
            color: #ff0000;
            font-size: 24px;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="warning-box">
        <h1 class="warning-title">🚨 非法访问检测 - 系统已自动报警 🚨</h1>
        <div class="info-item">
            <span class="info-label">入侵时间：</span>
            <span class="info-value"><?php echo $accessTime; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">你的公网IP：</span>
            <span class="info-value"><?php echo $clientIp; ?>（已被永久拉黑，禁止访问所有合规站点）</span>
        </div>
        <div class="info-item">
            <span class="info-label">精准定位信息：</span>
            <span class="info-value"><?php echo $ipLocation; ?></span>
        </div>
        <div class="danger-tip">
            <span class="danger-icon">⚠️</span>你的IP已被同步至国家网络安全监察系统！<br>
            <span class="danger-icon">⚠️</span>设备MAC地址、硬件信息已被完整采集存档！<br>
            <span class="danger-icon">⚠️</span>浏览器全信息、访问轨迹已永久分层归档作为定罪证据！<br>
            <span class="danger-icon">⚠️</span>请在24小时内联系管理员撤销备案，否则将面临行政处罚！
        </div>
        <div class="footer-alert">
            警告：本系统已开启全程录屏追踪，关闭页面无效，入侵行为将持续记录！
        </div>
    </div>
</body>
</html>
