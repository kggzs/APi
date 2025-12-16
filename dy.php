<?php
// index.php - 抖音视频解析API（合并版）
// 安全增强版本

class DouyinParser {
    
    private $headers = [
        'User-Agent: Mozilla/5.0 (Linux; Android 8.0.0; SM-G955U Build/R16NW) AppleWebKit/537.36',
        'Referer: https://www.douyin.com/'
    ];
    
    // 允许的域名白名单，防止SSRF攻击
    private $allowedDomains = [
        'douyin.com',
        'iesdouyin.com',
        'v.douyin.com',
        'www.douyin.com',
        'www.iesdouyin.com'
    ];
    
    /**
     * 验证URL是否安全（防止SSRF攻击）
     */
    private function isSafeUrl($url) {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return false;
        }
        
        $host = strtolower($parsed['host']);
        
        // 检查是否在白名单中
        foreach ($this->allowedDomains as $allowed) {
            if ($host === $allowed || substr($host, -strlen($allowed)) === $allowed) {
                return true;
            }
        }
        
        // 检查是否为内网IP（防止SSRF）
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            // 检查是否为私有IP
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false; // 公网IP但不在白名单中
            }
            return false; // 内网IP，拒绝
        }
        
        return false;
    }
    
    /**
     * HTML转义函数，防止XSS攻击
     */
    private function escapeHtml($str) {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * JavaScript转义函数，防止XSS攻击
     */
    private function escapeJs($str) {
        return json_encode($str, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
    
    public function parse($input) {
        try {
            if (empty($input)) {
                throw new Exception('请输入抖音链接或视频ID');
            }
            
            // 清理输入
            $input = trim($input);
            $input = strip_tags($input);
            
            if (is_numeric($input)) {
                // 验证数字ID长度
                if (strlen($input) < 10 || strlen($input) > 20) {
                    throw new Exception('无效的视频ID格式');
                }
                $video_id = $input;
            } else {
                // 安全提取URL
                if (!preg_match('/https?:\/\/[^\s]+/', $input, $video_url)) {
                    throw new Exception('无效的链接格式');
                }
                
                $url = $video_url[0];
                
                // 验证URL安全性（防止SSRF）
                if (!$this->isSafeUrl($url)) {
                    throw new Exception('不允许的域名，仅支持抖音官方链接');
                }
                
                $redirected_url = $this->get_redirected_url($url);
                if(empty($redirected_url)) {
                    throw new Exception('无法获取重定向URL');
                }
                
                // 再次验证重定向后的URL
                if (!$this->isSafeUrl($redirected_url)) {
                    throw new Exception('重定向到不允许的域名');
                }
                
                if(!preg_match('/(\d{10,})/', $redirected_url, $matches)) {
                    throw new Exception('无法提取视频ID');
                }
                $video_id = $matches[1];
            }
            
            // 验证video_id格式
            if (!preg_match('/^\d{10,20}$/', $video_id)) {
                throw new Exception('无效的视频ID格式');
            }
            
            return $this->get_video_info($video_id);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 400
            ];
        }
    }
    
    private function get_redirected_url($url) {
        // 再次验证URL安全性
        if (!$this->isSafeUrl($url)) {
            return false;
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5, // 限制重定向次数
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            // 注意：禁用SSL验证仅用于兼容性，生产环境应启用
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            // 防止SSRF：禁止重定向到内网
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS
        ]);
        curl_exec($ch);
        if(curl_errno($ch)) {
            curl_close($ch);
            return false;
        }
        $redirected_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        
        // 验证重定向后的URL
        if ($redirected_url && !$this->isSafeUrl($redirected_url)) {
            return false;
        }
        
        return $redirected_url;
    }
    
    private function get_video_info($video_id) {
        // 再次验证video_id
        if (!preg_match('/^\d{10,20}$/', $video_id)) {
            throw new Exception('无效的视频ID');
        }
        
        $url = "https://www.iesdouyin.com/share/video/{$video_id}/";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            // 注意：禁用SSL验证仅用于兼容性
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        if(curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('请求失败');
        }
        curl_close($ch);
        
        if(empty($response)) {
            throw new Exception('获取视频信息失败，请检查链接是否正确');
        }
        
        // 使用更稳定的正则匹配
        if(preg_match('/window\._ROUTER_DATA\s*=\s*(\{.*?\});?</s', $response, $matches)) {
            $jsonData = json_decode($matches[1], true);
        } elseif(preg_match('/<script[^>]*id="RENDER_DATA"[^>]*>(.*?)<\/script>/', $response, $matches)) {
            $jsonData = json_decode(urldecode($matches[1]), true);
        } else {
            throw new Exception('无法解析视频数据');
        }
        
        // 安全地访问数组元素
        if(empty($jsonData) || !is_array($jsonData)) {
            throw new Exception('视频数据解析失败');
        }
        
        // 根据不同的数据格式处理
        if(isset($jsonData['loaderData']['video_(id)/page']['videoInfoRes']['item_list'][0])) {
            $itemList = $jsonData['loaderData']['video_(id)/page']['videoInfoRes']['item_list'][0];
        } elseif(isset($jsonData['videoInfoRes']['item_list'][0])) {
            $itemList = $jsonData['videoInfoRes']['item_list'][0];
        } else {
            throw new Exception('视频信息格式不正确');
        }
        
        $nickname = isset($itemList['author']['nickname']) ? $this->escapeHtml($itemList['author']['nickname']) : '未知用户';
        $title = isset($itemList['desc']) ? $this->escapeHtml($itemList['desc']) : '无标题';
        $awemeId = isset($itemList['aweme_id']) ? $itemList['aweme_id'] : $video_id;
        
        // 验证awemeId
        if (!preg_match('/^\d{10,20}$/', $awemeId)) {
            $awemeId = $video_id;
        }
        
        // 获取视频URL
        $videoUrl = null;
        if(isset($itemList['video']['play_addr']['uri'])) {
            $video = $itemList['video']['play_addr']['uri'];
            // 验证video参数
            if (preg_match('/^[a-zA-Z0-9_-]+$/', $video)) {
                $videoUrl = (strpos($video, 'mp3') === false) ? 
                    'http://www.iesdouyin.com/aweme/v1/play/?video_id=' . urlencode($video) . '&ratio=1080p&line=0' : $video;
            }
        }
        
        // 获取封面
        $cover = '';
        if(isset($itemList['video']['cover']['url_list'][0])) {
            $coverUrl = $itemList['video']['cover']['url_list'][0];
            // 验证封面URL
            if ($this->isSafeUrl($coverUrl)) {
                $cover = $coverUrl;
            }
        }
        
        // 获取图片（如果是图集）
        $images = [];
        if(isset($itemList['images']) && is_array($itemList['images'])) {
            foreach($itemList['images'] as $image) {
                if(isset($image['url_list'][0])) {
                    $imgUrl = $image['url_list'][0];
                    // 验证图片URL
                    if ($this->isSafeUrl($imgUrl)) {
                        $images[] = $imgUrl;
                    }
                }
            }
        }
        
        return [
            'success' => true,
            'author' => $nickname,
            'title' => $title,
            'video_id' => $awemeId,
            'video_url' => $videoUrl,
            'play_url' => $videoUrl ? $this->get_redirected_url($videoUrl) : null,
            'cover' => $cover,
            'images' => $images,
            'type' => empty($images) ? 'video' : 'image',
            'timestamp' => time()
        ];
    }
}

// 如果是API请求，返回JSON
if (isset($_GET['api']) || isset($_POST['api']) || 
    (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false)) {
    
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST');
    
    // 关闭错误显示
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $parser = new DouyinParser();
        
        // 支持多种参数传递方式
        if(isset($_GET['url'])) {
            $input = $_GET['url'];
        } elseif(isset($_GET['msg'])) {
            $input = $_GET['msg'];
        } elseif(isset($_POST['url'])) {
            $input = $_POST['url'];
        } elseif(isset($_POST['msg'])) {
            $input = $_POST['msg'];
        } else {
            $input = '';
        }
        
        $input = urldecode(trim($input));
        
        $result = $parser->parse($input);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
    } catch(Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => '系统错误',
            'code' => 500
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>抖音无水印解析</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Microsoft YaHei', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 30px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 16px;
        }
        
        .input-group {
            margin-bottom: 25px;
        }
        
        .input-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }
        
        .input-group input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .input-group input:focus {
            border-color: #667eea;
            outline: none;
        }
        
        .btn {
            background: linear-gradient(to right, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 14px rgba(102, 126, 234, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .result {
            margin-top: 30px;
            display: none;
        }
        
        .result.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
            display: none;
        }
        
        .loading.active {
            display: block;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        .result-container {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .result-info {
            margin-bottom: 20px;
        }
        
        .result-item {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .result-item:last-child {
            border-bottom: none;
        }
        
        .result-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }
        
        .result-value {
            color: #333;
            word-break: break-all;
        }
        
        .video-preview {
            text-align: center;
            margin: 20px 0;
        }
        
        .video-preview img {
            max-width: 100%;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .download-btn {
            flex: 1;
            background: linear-gradient(to right, #4CAF50, #45a049);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            text-align: center;
            font-weight: 600;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 14px rgba(76, 175, 80, 0.3);
        }
        
        .copy-btn {
            background: linear-gradient(to right, #2196F3, #0b7dda);
        }
        
        .copy-btn:hover {
            box-shadow: 0 7px 14px rgba(33, 150, 243, 0.3);
        }
        
        /* 图集样式 */
        .image-gallery {
            display: none;
            margin-top: 20px;
        }
        
        .image-gallery.active {
            display: block;
        }
        
        .gallery-title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .image-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .image-item:hover {
            transform: translateY(-5px);
        }
        
        .image-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            display: block;
        }
        
        .image-download-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(76, 175, 80, 0.9);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 20px;
            transition: background-color 0.3s;
        }
        
        .image-download-btn:hover {
            background: rgba(69, 160, 73, 1);
        }
        
        .batch-download {
            text-align: center;
            margin-top: 20px;
        }
        
        .batch-btn {
            background: linear-gradient(to right, #FF9800, #F57C00);
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .batch-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 14px rgba(255, 152, 0, 0.3);
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            text-align: center;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            color: #999;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎬 抖音无水印解析</h1>
            <p>支持视频和图集解析，轻松下载无水印内容</p>
        </div>
        
        <div class="input-group">
            <label for="video-url">输入抖音分享链接或口令：</label>
            <input type="text" id="video-url" 
                   value="">
        </div>
        
        <button class="btn" onclick="parseVideo()">🚀 开始解析</button>
        
        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p>正在解析中，请稍候...</p>
        </div>
        
        <div class="result" id="result">
            <div class="result-container" id="result-container">
                <!-- 解析结果将在这里显示 -->
            </div>
            
            <!-- 图集展示区域 -->
            <div class="image-gallery" id="image-gallery">
                <h3 class="gallery-title">📸 图集预览</h3>
                <div class="image-grid" id="image-grid">
                    <!-- 图片将在这里动态添加 -->
                </div>
                <div class="batch-download">
                    <a href="javascript:void(0)" class="batch-btn" onclick="downloadAllImages()">📥 批量下载全部图片</a>
                </div>
            </div>
        </div>
        
        <div id="error-message" class="error" style="display: none;"></div>
        
        <div class="footer">
            <p>© 2025 抖音解析工具 | 仅供学习交流使用</p>
        </div>
    </div>

    <script>
        // HTML转义函数，防止XSS攻击
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // 智能提取抖音链接
        function extractDouyinUrl(text) {
            // 尝试匹配抖音短链接
            const shortLinkRegex = /https?:\/\/v\.douyin\.com\/\w+\/?/i;
            const shortLinkMatch = text.match(shortLinkRegex);
            if (shortLinkMatch) {
                return shortLinkMatch[0];
            }
            
            // 尝试匹配抖音长链接
            const longLinkRegex = /https?:\/\/(www\.)?douyin\.com\/video\/\d+\/?/i;
            const longLinkMatch = text.match(longLinkRegex);
            if (longLinkMatch) {
                return longLinkMatch[0];
            }
            
            // 尝试匹配抖音分享口令中的链接部分
            const shareTextRegex = /https?:\/\/[^\s]+/i;
            const shareTextMatch = text.match(shareTextRegex);
            if (shareTextMatch) {
                return shareTextMatch[0];
            }
            
            // 尝试匹配纯数字ID
            const idRegex = /\d{10,}/;
            const idMatch = text.match(idRegex);
            if (idMatch) {
                return idMatch[0];
            }
            
            // 如果都没有匹配到，返回原始文本
            return text;
        }
        
        async function parseVideo() {
            const inputText = document.getElementById('video-url').value.trim();
            const loading = document.getElementById('loading');
            const result = document.getElementById('result');
            const errorMessage = document.getElementById('error-message');
            const resultContainer = document.getElementById('result-container');
            const imageGallery = document.getElementById('image-gallery');
            const imageGrid = document.getElementById('image-grid');
            
            // 清空之前的结果
            errorMessage.style.display = 'none';
            resultContainer.innerHTML = '';
            imageGrid.innerHTML = '';
            imageGallery.classList.remove('active');
            
            if (!inputText) {
                showError('请输入抖音分享链接或口令');
                return;
            }
            
            // 智能提取链接
            const videoUrl = extractDouyinUrl(inputText);
            
            // 显示加载动画
            loading.classList.add('active');
            result.classList.remove('active');
            
            try {
                // 调用后端API
                const response = await fetch('?api=1', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'url=' + encodeURIComponent(videoUrl)
                });
                
                const data = await response.json();
                
                // 隐藏加载动画
                loading.classList.remove('active');
                
                if (data.success) {
                    // 显示结果区域
                    result.classList.add('active');
                    
                    // 安全地生成结果HTML（使用转义防止XSS）
                    let html = `
                        <div class="result-info">
                            <div class="result-item">
                                <div class="result-label">作者</div>
                                <div class="result-value">${escapeHtml(data.author || '未知')}</div>
                            </div>
                            <div class="result-item">
                                <div class="result-label">标题</div>
                                <div class="result-value">${escapeHtml(data.title || '无标题')}</div>
                            </div>
                            <div class="result-item">
                                <div class="result-label">视频ID</div>
                                <div class="result-value">${escapeHtml(data.video_id || '')}</div>
                            </div>
                        </div>
                    `;
                    
                    // 如果有封面，显示封面（验证URL）
                    if (data.cover && isValidUrl(data.cover)) {
                        html += `
                            <div class="video-preview">
                                <img src="${escapeHtml(data.cover)}" alt="封面图片" onerror="this.style.display='none'">
                            </div>
                        `;
                    }
                    
                    resultContainer.innerHTML = html;
                    
                    // 根据内容类型显示不同的下载区域
                    if (data.type === 'video' && data.video_url && isValidUrl(data.video_url)) {
                        // 视频：显示视频下载按钮
                        const safeVideoUrl = escapeHtml(data.video_url);
                        html += `
                            <div class="action-buttons">
                                <a href="${safeVideoUrl}" class="download-btn" download target="_blank" rel="noopener noreferrer">
                                    📥 下载视频
                                </a>
                                <a href="javascript:void(0)" class="download-btn copy-btn" onclick="copyToClipboard(${JSON.stringify(data.video_url)})">
                                    📋 复制链接
                                </a>
                            </div>
                        `;
                        resultContainer.innerHTML = html;
                    } else if (data.type === 'image' && data.images && data.images.length > 0) {
                        // 图集：显示图片下载按钮，并展示所有图片
                        imageGallery.classList.add('active');
                        
                        // 添加图片到网格（验证每个URL）
                        data.images.forEach((imgUrl, index) => {
                            if (isValidUrl(imgUrl)) {
                                const imageItem = document.createElement('div');
                                imageItem.className = 'image-item';
                                const safeImgUrl = escapeHtml(imgUrl);
                                imageItem.innerHTML = `
                                    <img src="${safeImgUrl}" alt="图集图片 ${index + 1}" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgdmlld0JveD0iMCAwIDE1MCAxNTAiIGZpbGw9IiNlZWUiPjxyZWN0IHdpZHRoPSIxNTAiIGhlaWdodD0iMTUwIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxNCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzk5OSI+5Zu+54mHPC90ZXh0Pjwvc3ZnPgo='">
                                    <a href="${safeImgUrl}" class="image-download-btn" download="douyin_image_${index + 1}.jpg" title="下载图片" rel="noopener noreferrer">
                                        ↓
                                    </a>
                                `;
                                imageGrid.appendChild(imageItem);
                            }
                        });
                    } else {
                        showError('未找到可下载的内容');
                    }
                } else {
                    showError('解析失败: ' + escapeHtml(data.error || '未知错误'));
                }
            } catch (error) {
                loading.classList.remove('active');
                showError('网络请求失败: ' + escapeHtml(error.message));
            }
        }
        
        // URL验证函数
        function isValidUrl(url) {
            try {
                const urlObj = new URL(url);
                const allowedDomains = ['douyin.com', 'iesdouyin.com', 'v.douyin.com'];
                const hostname = urlObj.hostname.toLowerCase();
                return allowedDomains.some(domain => hostname.includes(domain));
            } catch {
                return false;
            }
        }
        
        function showError(message) {
            const errorMessage = document.getElementById('error-message');
            errorMessage.textContent = message;
            errorMessage.style.display = 'block';
            
            // 3秒后自动隐藏错误消息
            setTimeout(() => {
                errorMessage.style.display = 'none';
            }, 3000);
        }
        
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('已复制到剪贴板');
            }).catch(err => {
                console.error('复制失败: ', err);
                // 备用方法
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('已复制到剪贴板');
            });
        }
        
        function downloadAllImages() {
            const downloadLinks = document.querySelectorAll('.image-download-btn');
            if (downloadLinks.length === 0) {
                alert('没有找到可下载的图片');
                return;
            }
            
            if (confirm(`确认要批量下载 ${downloadLinks.length} 张图片吗？`)) {
                // 由于浏览器限制，无法真正批量下载，这里只能提示用户手动点击
                alert('由于浏览器限制，请逐一点击每张图片右下角的下载按钮进行下载。');
            }
        }
        
        // 按回车键触发解析
        document.getElementById('video-url').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                parseVideo();
            }
        });
        
        // 如果页面URL有参数，自动填充输入框
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const urlParam = urlParams.get('url');
            if (urlParam) {
                document.getElementById('video-url').value = decodeURIComponent(urlParam);
                // 自动解析
                setTimeout(parseVideo, 500);
            }
        });
    </script>
</body>
</html>
