<?php
/**
 * 恶搞IP记录脚本（分层归档版 - CDN优化版本）
 * 功能：1. 按日期生成简易信息log；2. ip/年月日/ 文件夹存储IP命名的详细信息文件；3. 兼容PHP 7.x
 * 优化：支持CDN环境，IP定位缓存机制，输出压缩等性能优化
 */

// CDN优化：启用输出压缩（如果服务器支持，减少传输大小）
if (extension_loaded('zlib') && !ob_get_level() && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

// CDN优化：设置HTTP响应头（有助于CDN缓存和性能优化）
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// 管理页面密码（请修改为您自己的密码）
$adminPassword = 'admin123'; // 请修改此密码

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

// 辅助函数：检查IP是否为IPv4地址
function isIPv4($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

// 辅助函数：从IP列表中优先选择IPv4地址
function preferIPv4($ipList) {
    $ipv4List = [];
    $ipv6List = [];
    
    foreach ($ipList as $ip) {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (isIPv4($ip)) {
                $ipv4List[] = $ip;
            } else {
                $ipv6List[] = $ip;
            }
        }
    }
    
    // 优先返回IPv4地址列表，如果没有则返回IPv6列表
    return !empty($ipv4List) ? $ipv4List : $ipv6List;
}

// 步骤4：获取访问者的真实IP地址（优化支持CDN环境，优先获取IPv4地址）
function getClientRealIp() {
    $ip = '';
    $ipv4 = ''; // 专门存储IPv4地址
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // 判断是否经过CDN/代理（REMOTE_ADDR 为内网IP或已知CDN IP段）
    $isBehindProxy = false;
    if (!empty($remoteAddr)) {
        // 如果是内网IP，说明经过了反向代理/CDN
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            if (!filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $isBehindProxy = true;
            }
        }
    }
    
    // 优先级1：CDN/代理专用头部（由CDN服务器设置，可信度高，不容易被客户端伪造）
    // 优先收集IPv4地址
    // Cloudflare CDN
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
            if (isIPv4($cfIp)) {
                return $cfIp; // 直接返回IPv4
            } elseif (empty($ip)) {
                $ip = $cfIp; // 保存IPv6作为备选
            }
        }
    }
    
    // Fastly CDN
    if (!empty($_SERVER['HTTP_FASTLY_CLIENT_IP'])) {
        $fastlyIp = trim($_SERVER['HTTP_FASTLY_CLIENT_IP']);
        if (filter_var($fastlyIp, FILTER_VALIDATE_IP)) {
            if (isIPv4($fastlyIp)) {
                return $fastlyIp; // 直接返回IPv4
            } elseif (empty($ip)) {
                $ip = $fastlyIp; // 保存IPv6作为备选
            }
        }
    }
    
    // KeyCDN / Akamai
    if (!empty($_SERVER['HTTP_TRUE_CLIENT_IP'])) {
        $trueClientIp = trim($_SERVER['HTTP_TRUE_CLIENT_IP']);
        if (filter_var($trueClientIp, FILTER_VALIDATE_IP)) {
            if (isIPv4($trueClientIp)) {
                return $trueClientIp; // 直接返回IPv4
            } elseif (empty($ip)) {
                $ip = $trueClientIp; // 保存IPv6作为备选
            }
        }
    }
    
    // AWS CloudFront / AWS ELB
    if (!empty($_SERVER['HTTP_CLOUDFRONT_VIEWER_ADDRESS'])) {
        $cfViewer = trim(explode(':', $_SERVER['HTTP_CLOUDFRONT_VIEWER_ADDRESS'])[0]);
        if (filter_var($cfViewer, FILTER_VALIDATE_IP)) {
            if (isIPv4($cfViewer)) {
                return $cfViewer; // 直接返回IPv4
            } elseif (empty($ip)) {
                $ip = $cfViewer; // 保存IPv6作为备选
            }
        }
    }
    
    // 阿里云CDN / 腾讯云CDN（使用 X-Forwarded-For，优先获取IPv4）
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $xffList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $validIps = [];
        
        // 收集所有有效的公网IP
        foreach ($xffList as $xffIp) {
            $xffIp = trim($xffIp);
            if (filter_var($xffIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $validIps[] = $xffIp;
            }
        }
        
        // 优先选择IPv4地址
        if (!empty($validIps)) {
            $preferredIps = preferIPv4($validIps);
            if (!empty($preferredIps)) {
                // 如果有IPv4，优先使用第一个IPv4
                $xffIp = $preferredIps[0];
                if (isIPv4($xffIp)) {
                    $ipv4 = $xffIp; // 优先保存IPv4
                }
                if (empty($ip)) {
                    $ip = $xffIp;
                }
            }
        }
    }
    
    // X-Real-IP（Nginx反向代理常用，优先IPv4）
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $xri = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($xri, FILTER_VALIDATE_IP)) {
            if (isIPv4($xri)) {
                $ipv4 = $xri; // 优先保存IPv4
                if (empty($ip)) {
                    $ip = $xri;
                }
            } elseif (empty($ip)) {
                $ip = $xri;
            }
        }
    }
    
    // 优先级2：如果检测到经过了代理/CDN，必须使用代理头部获取的IP（优先IPv4）
    // 如果 REMOTE_ADDR 是内网IP，说明一定经过了代理，不能直接使用 REMOTE_ADDR
    if ($isBehindProxy) {
        // 优先返回IPv4地址
        if (!empty($ipv4) && filter_var($ipv4, FILTER_VALIDATE_IP)) {
            return $ipv4;
        }
        if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        // 如果代理头部也没有有效IP，使用 REMOTE_ADDR 作为备选（优先IPv4）
        if (!empty($remoteAddr) && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            if (isIPv4($remoteAddr)) {
                return $remoteAddr;
            }
            return $remoteAddr; // 即使是IPv6也返回
        }
    }
    
    // 优先级3：如果没有经过代理，REMOTE_ADDR 就是真实客户端IP（优先IPv4）
    if (!empty($remoteAddr)) {
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            // REMOTE_ADDR是公网IP，优先检查是否为IPv4
            if (isIPv4($remoteAddr)) {
                return $remoteAddr;
            }
            // 如果是IPv6，继续检查其他头部是否有IPv4
        }
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            // 保存REMOTE_ADDR作为备选
            if (isIPv4($remoteAddr)) {
                $ipv4 = $remoteAddr;
            }
            if (empty($ip)) {
                $ip = $remoteAddr;
            }
        }
    }
    
    // 优先级4：优先返回IPv4地址，如果没有则返回其他IP
    if (!empty($ipv4) && filter_var($ipv4, FILTER_VALIDATE_IP)) {
        return $ipv4;
    }
    if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    
    // 最终验证并返回，如果都不合法则返回默认IPv4地址
    return filter_var($ip, FILTER_VALIDATE_IP) ?: '127.0.0.1';
}

// 步骤4.5：VPN/代理检测和源IP分析
function detectVpnAndAnalyzeSourceIp($ip) {
    $result = [
        'is_vpn' => false,
        'is_proxy' => false,
        'is_tor' => false,
        'vpn_type' => '无',
        'proxy_type' => '无',
        'confidence' => 0,
        'source_ip_chain' => [],
        'possible_source_ip' => $ip,
        'detection_methods' => [],
        'ip_info' => null
    ];
    
    if (empty($ip) || in_array($ip, ['127.0.0.1', '::1'])) {
        return $result;
    }
    
    // 首先检查是否为CDN（CDN的头部不应该被误判为代理）
    $isCdn = false;
    $cdnTypes = [];
    
    // 检测常见CDN服务商的专用头部
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $isCdn = true;
        $cdnTypes[] = 'Cloudflare CDN';
    }
    if (!empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
        $isCdn = true;
        if (!in_array('Cloudflare CDN', $cdnTypes)) {
            $cdnTypes[] = 'Cloudflare CDN';
        }
    }
    if (!empty($_SERVER['HTTP_FASTLY_CLIENT_IP'])) {
        $isCdn = true;
        $cdnTypes[] = 'Fastly CDN';
    }
    if (!empty($_SERVER['HTTP_TRUE_CLIENT_IP'])) {
        $isCdn = true;
        $cdnTypes[] = 'Akamai/KeyCDN';
    }
    if (!empty($_SERVER['HTTP_CLOUDFRONT_VIEWER_ADDRESS'])) {
        $isCdn = true;
        $cdnTypes[] = 'AWS CloudFront CDN';
    }
    
    // 方法1：检查HTTP头部中的代理标识（排除CDN头部）
    // 注意：X-Forwarded-For 和 X-Real-IP 在CDN环境下也会存在，不能单独作为代理证据
    $proxyHeaders = [
        'HTTP_VIA' => 'HTTP-Via',
        'HTTP_X_PROXY_ID' => 'X-Proxy-ID',
        'HTTP_X_CLUSTER_CLIENT_IP' => 'X-Cluster-Client-IP',
        'HTTP_X_FORWARDED' => 'X-Forwarded',
        'HTTP_FORWARDED_FOR' => 'Forwarded-For',
        'HTTP_FORWARDED' => 'Forwarded',
        'HTTP_PROXY_CONNECTION' => 'Proxy-Connection'
    ];
    
    // 如果检测到CDN，则不检查X-Forwarded-For和X-Real-IP作为代理证据
    if (!$isCdn) {
        // 只有在非CDN环境下，才将X-Forwarded-For和X-Real-IP作为可能的代理证据
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && empty($_SERVER['HTTP_CF_CONNECTING_IP']) 
            && empty($_SERVER['HTTP_FASTLY_CLIENT_IP']) && empty($_SERVER['HTTP_TRUE_CLIENT_IP'])) {
            // 检查X-Forwarded-For是否包含私有IP，这可能表示经过了多层代理
            $xffList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $hasPrivateIp = false;
            foreach ($xffList as $xffIp) {
                $xffIp = trim($xffIp);
                if (filter_var($xffIp, FILTER_VALIDATE_IP) && 
                    !filter_var($xffIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $hasPrivateIp = true;
                    break;
                }
            }
            // 只有在包含私有IP时，才认为是代理（CDN通常不会包含私有IP）
            if (!$hasPrivateIp && count($xffList) > 1) {
                // 可能是CDN或反向代理，但不确定，不标记为代理
            }
        }
    }
    
    $detectedProxyHeaders = [];
    foreach ($proxyHeaders as $serverKey => $headerName) {
        if (!empty($_SERVER[$serverKey])) {
            $detectedProxyHeaders[] = $headerName . ': ' . $_SERVER[$serverKey];
            // 这些头部通常表示真实的HTTP代理（不是CDN）
            if (!$isCdn) {
                $result['is_proxy'] = true;
                $result['proxy_type'] = 'HTTP代理';
                $result['confidence'] += 25;
            }
        }
    }
    
    if (!empty($detectedProxyHeaders) && !$isCdn) {
        $result['detection_methods'][] = '检测到代理HTTP头部: ' . implode(', ', $detectedProxyHeaders);
    }
    
    // 如果是CDN，记录但不标记为代理
    if ($isCdn) {
        $result['detection_methods'][] = '检测到CDN服务: ' . implode(', ', $cdnTypes) . '（已排除CDN头部误判）';
    }
    
    // 方法2：分析X-Forwarded-For IP链，尝试找出源IP
    $ipChain = [];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $xffList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($xffList as $xffIp) {
            $xffIp = trim($xffIp);
            if (filter_var($xffIp, FILTER_VALIDATE_IP)) {
                $ipChain[] = $xffIp;
            }
        }
        $result['source_ip_chain'] = $ipChain;
        
        // 如果IP链中有多个IP，第一个通常是客户端IP（经过代理后的）
        // 最后一个可能是入口代理的IP
        if (count($ipChain) > 1) {
            // 尝试从链中找出最可能的源IP（通常是第一个公网IP）
            foreach ($ipChain as $chainIp) {
                if (filter_var($chainIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $result['possible_source_ip'] = $chainIp;
                    $result['detection_methods'][] = '从X-Forwarded-For链中提取可能的源IP: ' . $chainIp;
                    break;
                }
            }
        }
    }
    
    // 方法3：检查Tor网络（通过检查是否在Tor出口节点列表中，或检查特定头部）
    $torExitNodes = [
        // 这里可以添加已知的Tor出口节点IP段（部分示例）
        // 实际应用中应该调用Tor API或维护一个列表
    ];
    
    // 检查是否存在Tor特征
    if (!empty($_SERVER['HTTP_X_TOR'])) {
        $result['is_tor'] = true;
        $result['proxy_type'] = 'Tor网络';
        $result['confidence'] += 50;
        $result['detection_methods'][] = '检测到Tor网络标识头部';
    }
    
    // 方法4：调用IP检测API检查是否为VPN/代理（如果前面已检测到，跳过API调用以提升速度）
    // 如果已经通过其他方法检测到VPN/代理/Tor且置信度较高，跳过API调用
    if (!($result['is_vpn'] && $result['confidence'] > 50) && 
        !($result['is_proxy'] && $result['confidence'] > 50) && 
        !$result['is_tor']) {
        // 只有在不确定的情况下才调用API
        try {
            // 使用 ip-api.com 检测代理（需要pro版本支持）
            // 或使用其他免费API如 ip-api.com/json/{ip}?fields=proxy,hosting
            $apiUrl = "http://ip-api.com/json/{$ip}?fields=status,country,isp,org,query,proxy,hosting";
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 1, // 减少到1秒（快速失败）
                CURLOPT_CONNECTTIMEOUT => 1, // 连接超时1秒
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0',
            ]);
            
            $apiResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && !empty($apiResponse)) {
            $ipInfo = json_decode($apiResponse, true);
            if (!empty($ipInfo) && ($ipInfo['status'] ?? '') === 'success') {
                $result['ip_info'] = [
                    'country' => $ipInfo['country'] ?? '未知',
                    'isp' => $ipInfo['isp'] ?? '未知',
                    'org' => $ipInfo['org'] ?? '未知',
                    'query' => $ipInfo['query'] ?? $ip
                ];
                
                // 检查ISP和组织名称中是否包含VPN/代理关键词（排除CDN关键词）
                $vpnKeywords = ['vpn', 'proxy', 'tor'];
                $cdnKeywords = ['cloudflare', 'fastly', 'akamai', 'cloudfront', 'keycdn', 'maxcdn', 'cdn', 
                               'alibaba', 'tencent cloud', 'aliyun', 'aws', 'azure', 'google cloud'];
                $ispLower = strtolower($ipInfo['isp'] ?? '');
                $orgLower = strtolower($ipInfo['org'] ?? '');
                
                // 先检查是否为CDN
                $isCdnFromIsp = false;
                foreach ($cdnKeywords as $cdnKeyword) {
                    if (strpos($ispLower, $cdnKeyword) !== false || strpos($orgLower, $cdnKeyword) !== false) {
                        $isCdnFromIsp = true;
                        break;
                    }
                }
                
                // 只有非CDN的情况下才检查VPN关键词
                if (!$isCdnFromIsp && !$isCdn) {
                    foreach ($vpnKeywords as $keyword) {
                        if (strpos($ispLower, $keyword) !== false || strpos($orgLower, $keyword) !== false) {
                            $result['is_vpn'] = true;
                            $result['vpn_type'] = '检测到VPN/代理服务提供商';
                            $result['confidence'] += 30;
                            $result['detection_methods'][] = "ISP/组织名称包含VPN关键词: {$keyword}";
                            break;
                        }
                    }
                } elseif ($isCdnFromIsp) {
                    $result['detection_methods'][] = '检测到CDN服务商ISP/组织: ' . ($ipInfo['org'] ?? $ipInfo['isp'] ?? '未知') . '（已排除误判）';
                }
                
                // 检查是否为数据中心IP（排除CDN，可能是VPN/代理）
                if (isset($ipInfo['hosting']) && $ipInfo['hosting'] === true && !$isCdn && !$isCdnFromIsp) {
                    // 如果确认不是CDN，数据中心IP可能是VPN/代理
                    // 但需要结合其他证据，降低置信度
                    if ($result['confidence'] > 30) {
                        $result['is_proxy'] = true;
                        $result['proxy_type'] = '数据中心/托管IP';
                        $result['confidence'] += 15;
                        $result['detection_methods'][] = 'IP地址属于数据中心/托管服务';
                    }
                }
                
                // 检查是否标记为代理（ip-api.com pro版本支持）
                if (isset($ipInfo['proxy']) && $ipInfo['proxy'] === true) {
                    $result['is_proxy'] = true;
                    $result['proxy_type'] = '已确认的代理IP';
                    $result['confidence'] += 40;
                    $result['detection_methods'][] = 'IP检测API确认该IP为代理';
                }
            }
            } // 结束 if ($httpCode === 200)
        } catch (Exception $e) {
            // 静默失败，不影响主流程
        }
    } // 如果前面已检测到VPN/代理/Tor，跳过此API调用
    
    // 方法5：检查REMOTE_ADDR与获取的IP是否不同（可能是代理或CDN）
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($remoteAddr) && $remoteAddr !== $ip && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        if (!filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            // REMOTE_ADDR是内网IP，可能经过反向代理/CDN
            // 如果已检测到CDN，则不标记为代理
            if (!$isCdn) {
                // 只有在非CDN环境下才标记为代理
                $result['is_proxy'] = true;
                $result['proxy_type'] = '反向代理/负载均衡';
                $result['confidence'] += 20; // 降低置信度，因为也可能是CDN
                $result['detection_methods'][] = 'REMOTE_ADDR为内网IP，可能经过代理层（已排除CDN）';
            } else {
                $result['detection_methods'][] = 'REMOTE_ADDR为内网IP，但检测到CDN服务（已排除误判）';
            }
        }
    }
    
    // 方法6：检查已知VPN服务商IP段（部分常见VPN服务商）
    $vpnAsnPatterns = [
        'NordVPN', 'ExpressVPN', 'Surfshark', 'CyberGhost', 'Private Internet Access',
        'PureVPN', 'Hotspot Shield', 'VyprVPN', 'TunnelBear', 'Windscribe',
        'Mullvad', 'ProtonVPN', 'IPVanish', 'StrongVPN'
    ];
    
    if (!empty($result['ip_info']['org'])) {
        $org = $result['ip_info']['org'];
        foreach ($vpnAsnPatterns as $vpnName) {
            if (stripos($org, $vpnName) !== false) {
                $result['is_vpn'] = true;
                $result['vpn_type'] = $vpnName . ' VPN';
                $result['confidence'] += 50;
                $result['detection_methods'][] = "检测到已知VPN服务商: {$vpnName}";
                break;
            }
        }
    }
    
    // 最终判断：如果只因为CDN相关原因被标记为代理，则清除标记
    // 只有当置信度足够高（>50）且不是CDN时，才确认是代理
    if ($isCdn && $result['is_proxy'] && $result['confidence'] < 50) {
        // 如果检测到CDN且置信度低，可能是CDN误判，清除代理标记
        $result['is_proxy'] = false;
        $result['proxy_type'] = '无';
        $result['confidence'] = 0;
        $result['detection_methods'][] = '已排除CDN误判，未检测到真实代理';
    }
    
    // 最终判断
    if ($result['is_tor']) {
        $result['vpn_type'] = 'Tor网络';
        $result['proxy_type'] = 'Tor网络';
    } elseif ($result['is_vpn']) {
        // VPN优先级高于普通代理
        if (!$result['is_proxy']) {
            $result['proxy_type'] = $result['vpn_type'];
        }
    } elseif ($result['is_proxy']) {
        // 已经是代理标记
    }
    
    return $result;
}

// 步骤5：获取IP粗略定位（调用免费公开API，优化支持CDN，添加缓存机制提高性能）
function getIpLocation($ip) {
    if (in_array($ip, ['127.0.0.1', '::1'])) {
        return '本地主机 - 内网环境（已锁定设备MAC地址）';
    }
    
    // 创建缓存目录（用于缓存IP定位结果，提高性能，放在ip文件夹下）
    $cacheDir = 'ip/ip_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
        @chmod($cacheDir, 0755);
    }
    
    // 生成缓存文件名（使用IP地址作为文件名）
    $cacheFile = "{$cacheDir}/" . md5($ip) . '.json';
    $cacheExpire = 86400; // 缓存24小时（IP定位信息一般不会频繁变化）
    
    // 尝试从缓存读取
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheExpire) {
        $cachedData = @json_decode(file_get_contents($cacheFile), true);
        if (!empty($cachedData) && isset($cachedData['location'])) {
            return $cachedData['location'];
        }
    }
    
    // 调用API获取定位信息（优化curl配置，支持CDN环境）
    $apiUrl = "http://ip-api.com/json/{$ip}?lang=zh-CN";
    $ch = curl_init();
    
    // 构建curl选项数组（优化性能：快速失败机制）
    $curlOptions = [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 1, // 减少超时时间到1秒（快速失败）
        CURLOPT_CONNECTTIMEOUT => 1, // 连接超时1秒（快速失败）
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => false, // 禁用重定向（减少等待）
        CURLOPT_MAXREDIRS => 0, // 无重定向
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: zh-CN,zh;q=0.9',
        ],
        CURLOPT_ENCODING => '', // 自动接受压缩（gzip/deflate）
    ];
    
    // CDN优化：如果支持HTTP/2，则启用（PHP 7.0.7+）
    if (defined('CURL_HTTP_VERSION_2_0')) {
        $curlOptions[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
    }
    
    curl_setopt_array($ch, $curlOptions);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // 处理API响应
    if ($error || empty($response) || $httpCode !== 200) {
        // API失败时，尝试从缓存读取过期数据（总比没有好）
        if (file_exists($cacheFile)) {
            $cachedData = @json_decode(file_get_contents($cacheFile), true);
            if (!empty($cachedData) && isset($cachedData['location'])) {
                return $cachedData['location'] . '（缓存数据）';
            }
        }
        return '定位失败 - 已启动备用卫星定位系统追踪';
    }
    
    $result = json_decode($response, true);
    if (empty($result) || ($result['status'] ?? '') !== 'success') {
        return '定位失败 - 已触发全网IP追踪机制';
    }
    
    // 格式化定位信息
    $location = sprintf(
        '%s - %s %s %s - %s（精准定位误差≤50米，已关联所在区域监控）',
        $result['country'] ?? '未知',
        $result['regionName'] ?? '未知',
        $result['city'] ?? '未知',
        $result['zip'] ?? '',
        $result['isp'] ?? '未知'
    );
    
    // 保存到缓存
    @file_put_contents($cacheFile, json_encode(['location' => $location, 'timestamp' => time()], JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($cacheFile, 0644);
    
    return $location;
}

// 步骤6：采集浏览器/客户端完整详细信息（增强版，收集所有可用信息）
function collectBrowserAllInfo() {
    $browserInfo = [];
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // 一、客户端IP和请求基础信息
    $browserInfo['客户端基础信息'] = [
        '客户端IP地址' => getClientRealIp(),
        '请求方法' => $_SERVER['REQUEST_METHOD'] ?? '未知',
        '请求URI' => $_SERVER['REQUEST_URI'] ?? '未知',
        '完整页面URL' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                        '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
        '脚本路径（PHP_SELF）' => $_SERVER['PHP_SELF'] ?? '未知',
        '查询字符串（URL参数）' => $_SERVER['QUERY_STRING'] ?? '无查询参数',
        '访问来源（Referer）' => $_SERVER['HTTP_REFERER'] ?? '直接访问/无来源'
    ];
    
    // 二、浏览器和操作系统详细信息（增强解析）
    $uaParsed = parseUserAgent($userAgent);
    $browserInfo['浏览器详细信息'] = [
        '浏览器型号' => $uaParsed['browser_name'],
        '浏览器版本' => $uaParsed['browser_version'],
        '完整User-Agent' => $userAgent,
        '是否为爬虫' => $uaParsed['is_bot'] ? '是' : '否'
    ];
    
    $browserInfo['操作系统详细信息'] = [
        '操作系统类型' => $uaParsed['os_name'],
        '操作系统版本' => $uaParsed['os_version'],
        '设备类型' => $uaParsed['device_type'],
        '设备品牌' => $uaParsed['device_brand'] !== '未知' ? $uaParsed['device_brand'] : '未知',
        '设备型号' => $uaParsed['device_model'] !== '未知' ? $uaParsed['device_model'] : '未知',
        '是否为移动设备' => $uaParsed['is_mobile'] ? '是' : '否',
        '是否为平板设备' => $uaParsed['is_tablet'] ? '是' : '否'
    ];
    
    // 三、浏览器语言和本地化信息
    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $browserInfo['语言和本地化信息'] = [
        '浏览器语言（Accept-Language）' => $acceptLanguage,
        '主要语言' => !empty($acceptLanguage) ? explode(',', $acceptLanguage)[0] : '未知',
        '时区（JavaScript获取）' => '待JavaScript收集',
        '系统语言' => !empty($acceptLanguage) ? explode(',', $acceptLanguage)[0] : '未知'
    ];
    
    // 四、HTTP请求头信息
    $browserInfo['HTTP请求头信息'] = [
        '接受的内容类型（Accept）' => $_SERVER['HTTP_ACCEPT'] ?? '未知',
        '接受的编码格式（Accept-Encoding）' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '未知',
        '连接方式（Connection）' => $_SERVER['HTTP_CONNECTION'] ?? '未知',
        '主机地址（Host）' => $_SERVER['HTTP_HOST'] ?? '未知',
        '缓存控制（Cache-Control）' => $_SERVER['HTTP_CACHE_CONTROL'] ?? '无',
        '是否支持压缩（Accept-Encoding）' => (stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) ? '支持' : '不支持'
    ];
    
    // 五、Cookie信息（自身域名）
    $browserInfo['Cookie信息（自身域名）'] = !empty($_COOKIE) ? $_COOKIE : ['Cookie' => '无Cookie'];
    
    // 六、客户端环境信息（屏幕分辨率等由JavaScript收集，存储信息也整合到这里）
    $browserInfo['客户端环境信息'] = [
        '屏幕分辨率' => '待JavaScript收集',
        '可用屏幕尺寸' => '待JavaScript收集',
        '窗口大小' => '待JavaScript收集',
        '颜色深度' => '待JavaScript收集',
        '时区偏移' => '待JavaScript收集',
        'CPU核心数' => '待JavaScript收集',
        '内存信息' => '待JavaScript收集',
        '服务器端口' => $_SERVER['SERVER_PORT'] ?? '未知',
        '远程端口' => $_SERVER['REMOTE_PORT'] ?? '未知',
        '服务器软件' => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
        '网关接口' => $_SERVER['GATEWAY_INTERFACE'] ?? '未知'
    ];
    
    // 八、用户提交的表单数据
    $formData = [];
    if (!empty($_POST)) {
        foreach ($_POST as $key => $value) {
            if (is_array($value)) {
                $formData[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $formData[$key] = $value;
            }
        }
    }
    $browserInfo['用户提交的表单数据（POST）'] = !empty($formData) ? $formData : ['表单数据' => '无POST数据'];
    
    // 九、URL参数（GET）
    $browserInfo['URL参数（GET）'] = !empty($_GET) ? $_GET : ['GET参数' => '无GET参数'];
    
    // 十、上传文件信息
    $uploadFiles = [];
    if (!empty($_FILES)) {
        foreach ($_FILES as $fieldName => $fileInfo) {
            $uploadFiles[$fieldName] = [
                '文件名' => $fileInfo['name'] ?? '未知',
                '文件类型' => $fileInfo['type'] ?? '未知',
                '文件大小' => isset($fileInfo['size']) ? number_format($fileInfo['size']) . ' 字节' : '未知',
                '临时文件路径' => $fileInfo['tmp_name'] ?? '未知',
                '错误代码' => $fileInfo['error'] ?? '0'
            ];
        }
    }
    $browserInfo['上传文件信息'] = !empty($uploadFiles) ? $uploadFiles : ['上传文件' => '无文件上传'];
    
    // 十一、会话数据（$_SESSION）
    session_start();
    $browserInfo['会话数据（$_SESSION）'] = !empty($_SESSION) ? $_SESSION : ['会话数据' => '无SESSION数据'];
    session_write_close(); // 立即关闭会话以允许并发请求
    
    // 十二、其他环境变量（可能包含敏感信息，需谨慎）
    $browserInfo['其他环境信息'] = [
        '服务器协议' => $_SERVER['SERVER_PROTOCOL'] ?? '未知',
        '请求时间' => date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] ?? time()),
        '请求时间戳' => $_SERVER['REQUEST_TIME'] ?? time(),
        '文档根目录' => $_SERVER['DOCUMENT_ROOT'] ?? '未知',
        '脚本文件名' => $_SERVER['SCRIPT_FILENAME'] ?? '未知'
    ];
    
    return $browserInfo;
}

// 辅助函数：增强版UA解析（兼容PHP 7.x，提取浏览器型号/版本、操作系统版本等详细信息）
function parseUserAgent($userAgent) {
    $result = [
        'browser_name' => '未知',
        'browser_version' => '未知',
        'os_name' => '未知',
        'os_version' => '未知',
        'device_type' => '未知',
        'device_brand' => '未知',
        'device_model' => '未知',
        'is_mobile' => false,
        'is_tablet' => false,
        'is_bot' => false
    ];
    
    if (empty($userAgent)) {
        return $result;
    }
    
    // 检测是否为爬虫/机器人
    $bots = ['bot', 'crawler', 'spider', 'scraper', 'Googlebot', 'Bingbot', 'Slurp', 'DuckDuckBot'];
    foreach ($bots as $bot) {
        if (stripos($userAgent, $bot) !== false) {
            $result['is_bot'] = true;
            $result['browser_name'] = '爬虫程序';
            break;
        }
    }
    
    // 解析浏览器类型和版本
    // Chrome / Chrome-based browsers
    if (preg_match('/Chrome\/([\d.]+)/', $userAgent, $matches)) {
        if (stripos($userAgent, 'Edg') !== false) {
            // Microsoft Edge (Chromium)
            $result['browser_name'] = 'Microsoft Edge';
            if (preg_match('/Edg\/([\d.]+)/', $userAgent, $edgMatches)) {
                $result['browser_version'] = $edgMatches[1];
            }
        } elseif (stripos($userAgent, 'OPR') !== false) {
            // Opera
            $result['browser_name'] = 'Opera';
            if (preg_match('/OPR\/([\d.]+)/', $userAgent, $oprMatches)) {
                $result['browser_version'] = $oprMatches[1];
            }
        } else {
            $result['browser_name'] = 'Google Chrome';
            $result['browser_version'] = $matches[1];
        }
    }
    // Firefox
    elseif (preg_match('/Firefox\/([\d.]+)/', $userAgent, $matches)) {
        $result['browser_name'] = 'Mozilla Firefox';
        $result['browser_version'] = $matches[1];
    }
    // Safari (非Chrome)
    elseif (preg_match('/Safari\/([\d.]+)/', $userAgent, $matches) && stripos($userAgent, 'Chrome') === false) {
        $result['browser_name'] = 'Apple Safari';
        if (preg_match('/Version\/([\d.]+)/', $userAgent, $verMatches)) {
            $result['browser_version'] = $verMatches[1];
        }
    }
    // Internet Explorer
    elseif (preg_match('/MSIE ([\d.]+)/', $userAgent, $matches) || preg_match('/Trident\/.*rv:([\d.]+)/', $userAgent, $tridentMatches)) {
        $result['browser_name'] = 'Internet Explorer';
        $result['browser_version'] = isset($tridentMatches[1]) ? $tridentMatches[1] : $matches[1];
    }
    
    // 解析操作系统
    // Windows
    if (preg_match('/Windows NT ([\d.]+)/', $userAgent, $matches)) {
        $winVersions = [
            '10.0' => 'Windows 10/11',
            '6.3' => 'Windows 8.1',
            '6.2' => 'Windows 8',
            '6.1' => 'Windows 7',
            '6.0' => 'Windows Vista',
            '5.1' => 'Windows XP'
        ];
        $ntVersion = $matches[1];
        $result['os_name'] = $winVersions[$ntVersion] ?? "Windows NT {$ntVersion}";
        $result['os_version'] = $ntVersion;
    }
    // macOS
    elseif (preg_match('/Mac OS X ([\d_]+)/', $userAgent, $matches)) {
        $macVersion = str_replace('_', '.', $matches[1]);
        $result['os_name'] = 'macOS';
        $result['os_version'] = $macVersion;
    }
    // Android
    elseif (preg_match('/Android ([\d.]+)/', $userAgent, $matches)) {
        $result['os_name'] = 'Android';
        $result['os_version'] = $matches[1];
        $result['is_mobile'] = true;
        
        // 尝试提取设备型号
        if (preg_match('/(SM-[\w]+|Pixel \d+|MI \d+|OnePlus|Huawei|Xiaomi|OPPO|Vivo)/', $userAgent, $deviceMatches)) {
            $result['device_model'] = $deviceMatches[1];
        }
    }
    // iOS
    elseif (preg_match('/(iPhone|iPad|iPod).*OS ([\d_]+)/', $userAgent, $matches)) {
        $iosVersion = str_replace('_', '.', $matches[2]);
        $result['os_name'] = 'iOS';
        $result['os_version'] = $iosVersion;
        $result['device_brand'] = 'Apple';
        
        if (stripos($userAgent, 'iPad') !== false) {
            $result['device_type'] = '平板设备';
            $result['is_tablet'] = true;
        } else {
            $result['device_type'] = '手机设备';
            $result['is_mobile'] = true;
        }
        
        // 尝试提取iPhone型号
        if (preg_match('/(iPhone\d+,\d+|iPad\d+,\d+)/', $userAgent, $modelMatches)) {
            $result['device_model'] = $modelMatches[1];
        }
    }
    // Linux
    elseif (stripos($userAgent, 'Linux') !== false) {
        $result['os_name'] = 'Linux';
        if (preg_match('/(Ubuntu|Debian|CentOS|Fedora|Red Hat|SUSE)/', $userAgent, $distroMatches)) {
            $result['os_name'] = $distroMatches[1];
        }
    }
    
    // 设备类型判断
    if ($result['is_mobile'] || stripos($userAgent, 'Mobile') !== false) {
        $result['device_type'] = '移动设备';
    } elseif ($result['is_tablet']) {
        $result['device_type'] = '平板设备';
    } else {
        $result['device_type'] = '桌面设备';
    }
    
    return $result;
}

// 保留旧函数以兼容现有代码
function getBrowserType($userAgent) {
    $parsed = parseUserAgent($userAgent);
    return $parsed['browser_name'] . ($parsed['browser_version'] !== '未知' ? ' ' . $parsed['browser_version'] : '');
}

function getOsType($userAgent) {
    $parsed = parseUserAgent($userAgent);
    return $parsed['os_name'] . ($parsed['os_version'] !== '未知' ? ' ' . $parsed['os_version'] : '');
}

function getDeviceType($userAgent) {
    $parsed = parseUserAgent($userAgent);
    return $parsed['device_type'];
}

// 步骤7：处理JavaScript收集的客户端信息（如果存在）
$jsClientInfo = [];
if (isset($_POST['js_client_info']) && is_string($_POST['js_client_info'])) {
    $jsClientInfo = @json_decode($_POST['js_client_info'], true) ?: [];
}

// 辅助函数：将数字日期转换为中文日期（如：2026年1月17日 -> 二〇二六年一月十七日）
function convertDateToChinese($dateStr) {
    $chineseNumbers = ['〇', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
    $chineseUnits = ['', '十', '百', '千'];
    
    // 解析日期字符串
    if (preg_match('/(\d{4})年(\d{1,2})月(\d{1,2})日/', $dateStr, $matches)) {
        $year = $matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];
        
        // 转换年份
        $yearChinese = '';
        for ($i = 0; $i < strlen($year); $i++) {
            $yearChinese .= $chineseNumbers[(int)$year[$i]];
        }
        
        // 转换月份
        $monthChinese = '';
        if ($month < 10) {
            $monthChinese = $chineseNumbers[$month];
        } elseif ($month == 10) {
            $monthChinese = '十';
        } elseif ($month < 20) {
            $monthChinese = '十' . $chineseNumbers[$month % 10];
        } else {
            $monthChinese = $chineseNumbers[floor($month / 10)] . '十' . ($month % 10 > 0 ? $chineseNumbers[$month % 10] : '');
        }
        
        // 转换日期
        $dayChinese = '';
        if ($day < 10) {
            $dayChinese = $chineseNumbers[$day];
        } elseif ($day == 10) {
            $dayChinese = '十';
        } elseif ($day < 20) {
            $dayChinese = '十' . $chineseNumbers[$day % 10];
        } elseif ($day < 30) {
            $dayChinese = '二十' . ($day % 10 > 0 ? $chineseNumbers[$day % 10] : '');
        } else {
            $dayChinese = '三十' . ($day % 10 > 0 ? $chineseNumbers[$day % 10] : '');
        }
        
        return $yearChinese . '年' . $monthChinese . '月' . $dayChinese . '日';
    }
    
    // 如果格式不匹配，返回原字符串
    return $dateStr;
}

// 步骤8：核心逻辑执行（获取所有信息）
$clientIp = getClientRealIp();
$ipLocation = getIpLocation($clientIp);
$vpnDetection = detectVpnAndAnalyzeSourceIp($clientIp); // VPN/代理检测
$browserAllInfo = collectBrowserAllInfo();

// 转换日期格式（用于中文日期显示）
$chineseDate = convertDateToChinese(date('Y年n月j日'));

// 生成浏览器指纹（用于备案号）
$browserFingerprint = '';
$fingerprintData = [
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
    'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
    'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
    'connection' => $_SERVER['HTTP_CONNECTION'] ?? '',
];
$browserFingerprint = strtoupper(substr(md5(implode('|', $fingerprintData)), 0, 8));

// 将JavaScript收集的信息完整合并到浏览器信息中（移除独立分类，整合到对应分类）
if (!empty($jsClientInfo)) {
    // 1. 屏幕信息 - 合并到"客户端环境信息"
    if (isset($jsClientInfo['screen'])) {
        $screen = $jsClientInfo['screen'];
        $browserAllInfo['客户端环境信息']['屏幕分辨率'] = ($screen['width'] ?? '未知') . ' × ' . ($screen['height'] ?? '未知');
        $browserAllInfo['客户端环境信息']['可用屏幕尺寸'] = ($screen['availWidth'] ?? '未知') . ' × ' . ($screen['availHeight'] ?? '未知');
        $browserAllInfo['客户端环境信息']['颜色深度'] = ($screen['colorDepth'] ?? '未知') . ' 位';
        if (isset($screen['pixelDepth'])) {
            $browserAllInfo['客户端环境信息']['像素深度'] = $screen['pixelDepth'] . ' 位';
        }
    }
    
    // 2. 窗口信息 - 合并到"客户端环境信息"
    if (isset($jsClientInfo['window'])) {
        $window = $jsClientInfo['window'];
        $browserAllInfo['客户端环境信息']['窗口大小'] = ($window['width'] ?? '未知') . ' × ' . ($window['height'] ?? '未知');
        if (isset($window['outerWidth']) && isset($window['outerHeight'])) {
            $browserAllInfo['客户端环境信息']['窗口外部尺寸'] = $window['outerWidth'] . ' × ' . $window['outerHeight'];
        }
    }
    
    // 3. 时区信息 - 合并到"语言和本地化信息"
    if (isset($jsClientInfo['timezone'])) {
        $browserAllInfo['语言和本地化信息']['时区'] = $jsClientInfo['timezone'];
    }
    
    // 4. 存储信息 - 合并到"客户端环境信息"（移除独立的存储分类）
    if (isset($jsClientInfo['storage'])) {
        $storage = $jsClientInfo['storage'];
        // 移除"存储信息（JavaScript收集）"分类，直接合并到客户端环境信息
        if (isset($storage['localStorage']) && !empty($storage['localStorage'])) {
            $browserAllInfo['客户端环境信息']['本地存储（localStorage）'] = is_array($storage['localStorage']) ? 
                count($storage['localStorage']) . ' 项数据' : '有数据';
        }
        if (isset($storage['sessionStorage']) && !empty($storage['sessionStorage'])) {
            $browserAllInfo['客户端环境信息']['会话存储（sessionStorage）'] = is_array($storage['sessionStorage']) ? 
                count($storage['sessionStorage']) . ' 项数据' : '有数据';
        }
        // 移除原来的"存储信息（JavaScript收集）"分类
        if (isset($browserAllInfo['存储信息（JavaScript收集）'])) {
            unset($browserAllInfo['存储信息（JavaScript收集）']);
        }
    }
    
    // 5. 硬件信息 - 合并到"客户端环境信息"
    if (isset($jsClientInfo['hardware'])) {
        $hardware = $jsClientInfo['hardware'];
        if (isset($hardware['cpuCores'])) {
            $browserAllInfo['客户端环境信息']['CPU核心数'] = $hardware['cpuCores'];
        }
        if (isset($hardware['memory'])) {
            $browserAllInfo['客户端环境信息']['内存信息'] = $hardware['memory'];
        }
    }
    
    // 6. 浏览器语言详细信息 - 合并到"语言和本地化信息"
    if (isset($jsClientInfo['languages']) && is_array($jsClientInfo['languages'])) {
        $browserAllInfo['语言和本地化信息']['支持的语言列表'] = implode(', ', $jsClientInfo['languages']);
    }
    
    // 7. 平台信息 - 合并到"操作系统详细信息"
    if (isset($jsClientInfo['platform'])) {
        $browserAllInfo['操作系统详细信息']['客户端平台'] = $jsClientInfo['platform'];
    }
    
    // 8. 在线状态 - 合并到"客户端环境信息"
    if (isset($jsClientInfo['onLine'])) {
        $browserAllInfo['客户端环境信息']['在线状态'] = $jsClientInfo['onLine'];
    }
    
    // 9. URL详细信息 - 合并到"客户端基础信息"
    if (isset($jsClientInfo['url'])) {
        $url = $jsClientInfo['url'];
        // 如果JavaScript获取的URL与PHP获取的不同，使用JS的（更准确）
        if (isset($url['href'])) {
            $browserAllInfo['客户端基础信息']['完整页面URL（JS获取）'] = $url['href'];
        }
        if (isset($url['pathname'])) {
            $browserAllInfo['客户端基础信息']['页面路径（JS获取）'] = $url['pathname'];
        }
        if (isset($url['search']) && !empty($url['search'])) {
            $browserAllInfo['客户端基础信息']['URL参数（JS获取）'] = $url['search'];
        }
        if (isset($url['hash']) && !empty($url['hash'])) {
            $browserAllInfo['客户端基础信息']['URL锚点'] = $url['hash'];
        }
    }
    
    // 10. Cookie（JavaScript读取的补充） - 合并到"Cookie信息（自身域名）"
    if (isset($jsClientInfo['cookies']) && !empty($jsClientInfo['cookies']) && is_array($jsClientInfo['cookies'])) {
        // 合并JS读取的Cookie到现有Cookie信息中
        if (isset($browserAllInfo['Cookie信息（自身域名）'])) {
            $browserAllInfo['Cookie信息（自身域名）'] = array_merge(
                $browserAllInfo['Cookie信息（自身域名）'] ?? [],
                $jsClientInfo['cookies']
            );
        } else {
            $browserAllInfo['Cookie信息（自身域名）'] = $jsClientInfo['cookies'];
        }
    }
}

// 将VPN检测信息添加到浏览器信息中（如果已执行检测）
if (isset($vpnDetection)) {
    $browserAllInfo['VPN/代理检测结果'] = [
        '是否使用VPN' => $vpnDetection['is_vpn'] ? '是' : '否',
        '是否使用代理' => $vpnDetection['is_proxy'] ? '是' : '否',
        '是否Tor网络' => $vpnDetection['is_tor'] ? '是' : '否',
        'VPN类型' => $vpnDetection['vpn_type'],
        '代理类型' => $vpnDetection['proxy_type'],
        '检测置信度' => $vpnDetection['confidence'] . '%',
        '可能源IP地址' => $vpnDetection['possible_source_ip'],
        'IP链（X-Forwarded-For）' => !empty($vpnDetection['source_ip_chain']) ? implode(' -> ', $vpnDetection['source_ip_chain']) : '无',
        'IP详细信息（国家/ISP/组织）' => !empty($vpnDetection['ip_info']) ? 
            ($vpnDetection['ip_info']['country'] ?? '未知') . ' / ' . 
            ($vpnDetection['ip_info']['isp'] ?? '未知') . ' / ' . 
            ($vpnDetection['ip_info']['org'] ?? '未知') : '未知',
        '检测方法' => !empty($vpnDetection['detection_methods']) ? implode('; ', $vpnDetection['detection_methods']) : '无'
    ];
}

// 步骤9：拼接两类日志内容（简易信息 + 详细信息）
// 8.1 简易信息（用于日期log文件，简洁明了）
$vpnStatus = '';
if ($vpnDetection['is_vpn']) {
    $vpnStatus = ' | VPN检测：' . $vpnDetection['vpn_type'];
} elseif ($vpnDetection['is_proxy']) {
    $vpnStatus = ' | 代理检测：' . $vpnDetection['proxy_type'];
} elseif ($vpnDetection['is_tor']) {
    $vpnStatus = ' | Tor网络';
}
if ($vpnDetection['possible_source_ip'] !== $clientIp) {
    $vpnStatus .= ' | 可能源IP：' . $vpnDetection['possible_source_ip'];
}
$simpleLogContent = "访问时间：{$accessTime} | IP地址：{$clientIp} | IP定位：{$ipLocation}{$vpnStatus}" . PHP_EOL;

// 8.2 详细信息（用于IP命名文件，完整归档所有采集内容）
$detailedLogContent = "=== 详细访问记录（IP：{$clientIp}）===\n";
$detailedLogContent .= "创建时间：{$accessTime}\n";
$detailedLogContent .= "IP地址：{$clientIp}\n";
$detailedLogContent .= "IP定位：{$ipLocation}\n\n";

// 辅助函数：格式化信息值为易读格式（处理布尔值、数组、空值等）
function formatInfoValue($value, $key = '') {
    // 布尔值转换为"是/否"
    if (is_bool($value)) {
        return $value ? '是' : '否';
    }
    
    // 空值、null、空字符串
    if ($value === null || $value === '' || $value === '未知' || $value === '无' || $value === '无数据') {
        return null; // 返回null表示不显示
    }
    
    // 数组处理
    if (is_array($value)) {
        // 如果数组为空，返回null
        if (empty($value)) {
            return null;
        }
        
        // 如果是关联数组，格式化输出
        $formatted = [];
        foreach ($value as $subKey => $subValue) {
            $formattedSubValue = formatInfoValue($subValue, $subKey);
            if ($formattedSubValue !== null) {
                // 如果键是数字，只显示值；否则显示键值对
                if (is_numeric($subKey)) {
                    $formatted[] = $formattedSubValue;
                } else {
                    $formatted[] = "{$subKey}: {$formattedSubValue}";
                }
            }
        }
        
        if (empty($formatted)) {
            return null;
        }
        
        // 根据数组类型决定格式
        // 如果格式化后的数组元素少于等于2个，用逗号分隔
        // 如果2-5个，用分号分隔
        // 如果超过5个，用换行分隔
        if (count($formatted) <= 2) {
            return implode(', ', $formatted);
        } elseif (count($formatted) <= 5) {
            return implode('; ', $formatted);
        } else {
            return "\n    " . implode("\n    ", $formatted);
        }
    }
    
    // 字符串值处理
    if (is_string($value)) {
        // 尝试解析JSON字符串（如Cookie信息可能是JSON编码的）
        if (strlen($value) > 2 && ($value[0] === '{' || $value[0] === '[')) {
            $jsonDecoded = @json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonDecoded)) {
                // 递归处理解析后的数组
                return formatInfoValue($jsonDecoded, $key);
            }
        }
        
        // 检查是否包含"未知"、"无"等无用信息
        $uselessValues = ['未知', '无', '无数据', '无Cookie', '无GET参数', '无POST数据', '无文件上传', '无SESSION数据', '待JavaScript收集'];
        foreach ($uselessValues as $useless) {
            if ($value === $useless || strpos($value, $useless) !== false) {
                // 如果值只包含无用信息，返回null；如果包含其他信息，保留
                if (trim($value) === $useless) {
                    return null;
                }
            }
        }
        
        // 如果字符串太长，可能需要截断（可选）
        if (strlen($value) > 500) {
            return substr($value, 0, 500) . '...（已截断）';
        }
    }
    
    return $value;
}

// 辅助函数：判断信息是否应该显示
function shouldDisplayInfo($key, $value) {
    // 过滤掉某些不需要显示的键
    $hiddenKeys = ['error'];
    
    foreach ($hiddenKeys as $hidden) {
        if (stripos($key, $hidden) !== false) {
            return false;
        }
    }
    
    // 格式化值并检查是否为空
    $formatted = formatInfoValue($value, $key);
    return $formatted !== null;
}

// 添加VPN/代理检测信息到详细日志（优化格式）
$detailedLogContent .= "【VPN/代理检测结果】\n";
$detailedLogContent .= "  是否使用VPN：" . formatInfoValue($vpnDetection['is_vpn']) . "\n";
$detailedLogContent .= "  是否使用代理：" . formatInfoValue($vpnDetection['is_proxy']) . "\n";
$detailedLogContent .= "  是否Tor网络：" . formatInfoValue($vpnDetection['is_tor']) . "\n";

if ($vpnDetection['vpn_type'] !== '无') {
    $detailedLogContent .= "  VPN类型：{$vpnDetection['vpn_type']}\n";
}
if ($vpnDetection['proxy_type'] !== '无') {
    $detailedLogContent .= "  代理类型：{$vpnDetection['proxy_type']}\n";
}
if ($vpnDetection['confidence'] > 0) {
    $detailedLogContent .= "  检测置信度：{$vpnDetection['confidence']}%\n";
}
if ($vpnDetection['possible_source_ip'] !== $clientIp) {
    $detailedLogContent .= "  可能源IP：{$vpnDetection['possible_source_ip']}\n";
}
if (!empty($vpnDetection['source_ip_chain']) && count($vpnDetection['source_ip_chain']) > 1) {
    $detailedLogContent .= "  IP链（X-Forwarded-For）：" . implode(' -> ', $vpnDetection['source_ip_chain']) . "\n";
}
if (!empty($vpnDetection['ip_info'])) {
    $ipInfo = $vpnDetection['ip_info'];
    $ipInfoStr = [];
    if (!empty($ipInfo['country']) && $ipInfo['country'] !== '未知') {
        $ipInfoStr[] = "国家：{$ipInfo['country']}";
    }
    if (!empty($ipInfo['isp']) && $ipInfo['isp'] !== '未知') {
        $ipInfoStr[] = "ISP：{$ipInfo['isp']}";
    }
    if (!empty($ipInfo['org']) && $ipInfo['org'] !== '未知') {
        $ipInfoStr[] = "组织：{$ipInfo['org']}";
    }
    if (!empty($ipInfoStr)) {
        $detailedLogContent .= "  IP信息：" . implode(' | ', $ipInfoStr) . "\n";
    }
}
if (!empty($vpnDetection['detection_methods'])) {
    $detailedLogContent .= "  检测方法：" . implode('; ', $vpnDetection['detection_methods']) . "\n";
}
$detailedLogContent .= "\n";

// 优化详细信息写入格式（整合并过滤无用信息）
foreach ($browserAllInfo as $infoType => $infoDetails) {
    if (!is_array($infoDetails) || empty($infoDetails)) {
        continue;
    }
    
    // 先过滤出需要显示的信息
    $displayItems = [];
    foreach ($infoDetails as $infoKey => $infoValue) {
        if (shouldDisplayInfo($infoKey, $infoValue)) {
            $formattedValue = formatInfoValue($infoValue, $infoKey);
            if ($formattedValue !== null) {
                $displayItems[$infoKey] = $formattedValue;
            }
        }
    }
    
    // 只显示有意义的分类
    if (!empty($displayItems)) {
        $detailedLogContent .= "【{$infoType}】\n";
        foreach ($displayItems as $infoKey => $formattedValue) {
            // 如果值是数组且已格式化为多行，使用不同的缩进
            if (is_string($formattedValue) && strpos($formattedValue, "\n") !== false) {
                $detailedLogContent .= "  {$infoKey}：{$formattedValue}\n";
            } else {
                $detailedLogContent .= "  {$infoKey}：{$formattedValue}\n";
            }
        }
        $detailedLogContent .= "\n";
    }
}
$detailedLogContent .= "=== 记录结束 ===\n";

// 步骤10：写入简易信息（按日期生成log文件，存放于ip根文件夹）
// 只在非JavaScript请求时写入，避免重复记录
$simpleLogFileName = "{$rootIpFolder}/access_simple_{$accessDate}.txt";

// 检查是否为JavaScript请求（避免重复记录）
$postKeys = array_keys($_POST ?? []);
$isJsOnlyRequest = isset($_POST['js_client_info']) && 
                   empty($_GET) && 
                   (empty($postKeys) || (count($postKeys) === 1 && $postKeys[0] === 'js_client_info'));

if (!$isJsOnlyRequest) {
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
}

// 步骤11：写入详细信息（以IP为文件名，存放于当日日期子文件夹）
$detailedFileName = "{$dateFolderPath}/{$clientIp}.txt"; // 路径：ip/2026-01-16/127.0.0.1.txt

// 检查是否为重复IP访问（文件已存在）
$isRepeatAccess = file_exists($detailedFileName) && !$isJsOnlyRequest;

if ($isJsOnlyRequest && !empty($jsClientInfo) && file_exists($detailedFileName)) {
    // JavaScript单独提交时，生成整合后的补充信息（仅包含JS收集的新信息）
    $jsSupplementContent = "\n\n=== JavaScript客户端信息补充（收集时间：{$accessTime}）===\n";
    
    // 只显示JS收集的实际信息，按分类整合
    $jsDataToShow = [];
    
    if (isset($jsClientInfo['screen'])) {
        $screen = $jsClientInfo['screen'];
        $jsDataToShow['客户端环境信息（JS补充）'] = [];
        $jsDataToShow['客户端环境信息（JS补充）']['屏幕分辨率'] = ($screen['width'] ?? '未知') . ' × ' . ($screen['height'] ?? '未知');
        $jsDataToShow['客户端环境信息（JS补充）']['可用屏幕尺寸'] = ($screen['availWidth'] ?? '未知') . ' × ' . ($screen['availHeight'] ?? '未知');
        $jsDataToShow['客户端环境信息（JS补充）']['颜色深度'] = ($screen['colorDepth'] ?? '未知') . ' 位';
        if (isset($screen['pixelDepth'])) {
            $jsDataToShow['客户端环境信息（JS补充）']['像素深度'] = $screen['pixelDepth'] . ' 位';
        }
    }
    
    if (isset($jsClientInfo['window'])) {
        if (!isset($jsDataToShow['客户端环境信息（JS补充）'])) {
            $jsDataToShow['客户端环境信息（JS补充）'] = [];
        }
        $window = $jsClientInfo['window'];
        $jsDataToShow['客户端环境信息（JS补充）']['窗口大小'] = ($window['width'] ?? '未知') . ' × ' . ($window['height'] ?? '未知');
        if (isset($window['outerWidth']) && isset($window['outerHeight'])) {
            $jsDataToShow['客户端环境信息（JS补充）']['窗口外部尺寸'] = $window['outerWidth'] . ' × ' . $window['outerHeight'];
        }
    }
    
    if (isset($jsClientInfo['timezone'])) {
        $jsDataToShow['语言和本地化信息（JS补充）'] = ['时区' => $jsClientInfo['timezone']];
    }
    
    if (isset($jsClientInfo['storage'])) {
        if (!isset($jsDataToShow['客户端环境信息（JS补充）'])) {
            $jsDataToShow['客户端环境信息（JS补充）'] = [];
        }
        $storage = $jsClientInfo['storage'];
        if (isset($storage['localStorage']) && !empty($storage['localStorage'])) {
            $jsDataToShow['客户端环境信息（JS补充）']['本地存储'] = is_array($storage['localStorage']) ? 
                count($storage['localStorage']) . ' 项' : '有数据';
        }
        if (isset($storage['sessionStorage']) && !empty($storage['sessionStorage'])) {
            $jsDataToShow['客户端环境信息（JS补充）']['会话存储'] = is_array($storage['sessionStorage']) ? 
                count($storage['sessionStorage']) . ' 项' : '有数据';
        }
    }
    
    if (isset($jsClientInfo['hardware'])) {
        if (!isset($jsDataToShow['客户端环境信息（JS补充）'])) {
            $jsDataToShow['客户端环境信息（JS补充）'] = [];
        }
        $hardware = $jsClientInfo['hardware'];
        if (isset($hardware['cpuCores'])) {
            $jsDataToShow['客户端环境信息（JS补充）']['CPU核心数'] = $hardware['cpuCores'];
        }
        if (isset($hardware['memory'])) {
            $jsDataToShow['客户端环境信息（JS补充）']['内存信息'] = $hardware['memory'];
        }
    }
    
    if (isset($jsClientInfo['languages']) && is_array($jsClientInfo['languages'])) {
        if (!isset($jsDataToShow['语言和本地化信息（JS补充）'])) {
            $jsDataToShow['语言和本地化信息（JS补充）'] = [];
        }
        $jsDataToShow['语言和本地化信息（JS补充）']['支持的语言列表'] = implode(', ', $jsClientInfo['languages']);
    }
    
    if (isset($jsClientInfo['url'])) {
        $jsDataToShow['URL信息（JS补充）'] = $jsClientInfo['url'];
    }
    
    if (isset($jsClientInfo['platform'])) {
        if (!isset($jsDataToShow['操作系统详细信息（JS补充）'])) {
            $jsDataToShow['操作系统详细信息（JS补充）'] = [];
        }
        $jsDataToShow['操作系统详细信息（JS补充）']['客户端平台'] = $jsClientInfo['platform'];
    }
    
    // 格式化输出
    if (!empty($jsDataToShow)) {
        foreach ($jsDataToShow as $category => $items) {
            $jsSupplementContent .= "【{$category}】\n";
            foreach ($items as $key => $value) {
                if (is_array($value)) {
                    $jsSupplementContent .= "  {$key}：" . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
                } else {
                    $formattedValue = formatInfoValue($value);
                    if ($formattedValue !== null) {
                        $jsSupplementContent .= "  {$key}：{$formattedValue}\n";
                    }
                }
            }
            $jsSupplementContent .= "\n";
        }
    }
    
    $jsSupplementContent .= "=== JavaScript信息补充结束 ===\n";
    @file_put_contents($detailedFileName, $jsSupplementContent, FILE_APPEND | LOCK_EX);
    // 不输出HTML，只返回200状态码
    http_response_code(200);
    exit;
} elseif ($isRepeatAccess) {
    // 重复IP访问，只记录访问时间
    $accessTimeRecord = "  → 再次访问时间：{$accessTime}\n";
    @file_put_contents($detailedFileName, $accessTimeRecord, FILE_APPEND | LOCK_EX);
} else {
    // 首次访问，写入完整详细信息 - JS信息已整合在$browserAllInfo中
    @file_put_contents($detailedFileName, $detailedLogContent . "\n\n", FILE_APPEND | LOCK_EX);
    @chmod($detailedFileName, 0644);
}

// 步骤11.5：更新每日统计文件（只在非JavaScript请求时更新，避免重复记录）
if (!$isJsOnlyRequest) {
    $statsFileName = "{$rootIpFolder}/stats_{$accessDate}.txt";
    
    // 读取现有统计数据（JSON格式存储）
    $statsData = [
        'total_visits' => 0,
        'unique_ips' => [],
        'repeat_visits' => 0,
        'visit_times' => []
    ];
    
    if (file_exists($statsFileName)) {
        // 尝试读取JSON格式的统计文件（如果有隐藏的JSON数据）
        $statsJsonFile = $statsFileName . '.json';
        if (file_exists($statsJsonFile)) {
            $jsonData = @json_decode(file_get_contents($statsJsonFile), true);
            if ($jsonData && is_array($jsonData)) {
                $statsData = $jsonData;
            }
        } else {
            // 如果没有JSON文件，从文本文件中解析
            $statsContent = @file_get_contents($statsFileName);
            if ($statsContent) {
                // 提取统计数据
                if (preg_match('/总访问次数：(\d+)/', $statsContent, $matches)) {
                    $statsData['total_visits'] = (int)$matches[1];
                }
                if (preg_match('/重复访问次数：(\d+)/', $statsContent, $matches)) {
                    $statsData['repeat_visits'] = (int)$matches[1];
                }
                // 提取IP列表
                if (preg_match_all('/- ([\d\.]+)/', $statsContent, $matches)) {
                    $statsData['unique_ips'] = array_unique($matches[1]);
                }
            }
        }
    }
    
    // 更新统计数据
    $statsData['total_visits']++;
    
    if (!$isRepeatAccess) {
        // 首次访问，添加到独立IP列表
        if (!in_array($clientIp, $statsData['unique_ips'])) {
            $statsData['unique_ips'][] = $clientIp;
        }
    } else {
        // 重复访问，增加重复访问次数
        $statsData['repeat_visits']++;
    }
    
    // 记录访问时间（最多保留50条）
    $statsData['visit_times'][] = $accessTime;
    if (count($statsData['visit_times']) > 50) {
        $statsData['visit_times'] = array_slice($statsData['visit_times'], -50);
    }
    
    // 保存JSON格式的统计数据（便于下次读取）
    $statsJsonFile = $statsFileName . '.json';
    @file_put_contents($statsJsonFile, json_encode($statsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($statsJsonFile, 0644);
    
    // 生成统计文件内容（人类可读格式）
    $statsContent = "=== 每日访问统计 ===\n";
    $statsContent .= "统计日期：{$accessDate}\n";
    $statsContent .= "最后更新：{$accessTime}\n";
    $statsContent .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $statsContent .= "【访问统计】\n";
    $statsContent .= "总访问次数：{$statsData['total_visits']}\n";
    $statsContent .= "独立IP数量：" . count($statsData['unique_ips']) . "\n";
    $statsContent .= "重复访问次数：{$statsData['repeat_visits']}\n";
    $statsContent .= "新访问次数：" . ($statsData['total_visits'] - $statsData['repeat_visits']) . "\n\n";
    
    // IP列表（所有IP）
    $statsContent .= "【IP列表】（共 " . count($statsData['unique_ips']) . " 个）\n";
    foreach ($statsData['unique_ips'] as $ip) {
        $statsContent .= "  - {$ip}\n";
    }
    
    // 访问时间（最近20条）
    $statsContent .= "\n【最近访问时间】（最近20条，共 " . count($statsData['visit_times']) . " 条）\n";
    $recentVisits = array_slice($statsData['visit_times'], -20);
    foreach ($recentVisits as $time) {
        $statsContent .= "  - {$time}\n";
    }
    
    $statsContent .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $statsContent .= "说明：本文件自动生成，记录每日访问统计信息\n";
    
    // 写入统计文件
    @file_put_contents($statsFileName, $statsContent, LOCK_EX);
    @chmod($statsFileName, 0644);
}

// 管理页面检查（在正常流程之前）
if (isset($_GET['admin']) && $_GET['admin'] === $adminPassword) {
    // 显示管理页面
    $action = $_GET['action'] ?? '';
    $viewFile = $_GET['file'] ?? '';
    
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>日志管理系统</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            background: #f5f5f5;
            font-family: "Microsoft YaHei", "SimHei", Arial, sans-serif;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 20px;
        }
        .header {
            border-bottom: 2px solid #1890ff;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header h1 {
            color: #1890ff;
            font-size: 24px;
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid #e8e8e8;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border: none;
            background: none;
            font-size: 14px;
            color: #666;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }
        .tab.active {
            color: #1890ff;
            border-bottom-color: #1890ff;
        }
        .tab:hover {
            color: #1890ff;
        }
        .content-area {
            margin-top: 20px;
        }
        .file-list {
            list-style: none;
        }
        .file-item {
            padding: 12px;
            border: 1px solid #e8e8e8;
            margin-bottom: 10px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }
        .file-item:hover {
            background: #f5f5f5;
            border-color: #1890ff;
        }
        .file-name {
            font-weight: 500;
            color: #262626;
            }
        .file-size {
            color: #8c8c8c;
            font-size: 12px;
            margin-left: 10px;
        }
        .file-date {
            color: #8c8c8c;
            font-size: 12px;
        }
        .btn {
            padding: 6px 16px;
            border: 1px solid #1890ff;
            background: #1890ff;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 13px;
            transition: all 0.3s;
        }
        .btn:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }
        .btn-secondary {
            background: #fff;
            color: #1890ff;
        }
        .btn-secondary:hover {
            background: #e6f7ff;
        }
        .log-content {
            background: #fafafa;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            padding: 15px;
            max-height: 600px;
            overflow-y: auto;
            font-family: "Courier New", monospace;
            font-size: 13px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .back-btn {
            margin-bottom: 15px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: linear-gradient(135deg, #1890ff 0%, #096dd9 100%);
            color: #fff;
            padding: 20px;
            border-radius: 4px;
        }
        .stat-card h3 {
            font-size: 14px;
            margin-bottom: 10px;
            opacity: 0.9;
        }
        .stat-card .value {
            font-size: 28px;
            font-weight: bold;
        }
        .date-folder {
            margin-bottom: 30px;
        }
        .date-folder h3 {
            color: #262626;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e8e8e8;
        }
        .date-selector {
            background: #fafafa;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .date-selector h3 {
            color: #262626;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .date-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
        }
        .date-item {
            padding: 10px 15px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #fff;
            text-decoration: none;
            color: #262626;
            display: block;
        }
        .date-item:hover {
            border-color: #1890ff;
            background: #e6f7ff;
            color: #1890ff;
        }
        .date-item.active {
            border-color: #1890ff;
            background: #1890ff;
            color: #fff;
        }
        .date-item .date-text {
            font-size: 14px;
            font-weight: 500;
        }
        .date-item .file-count {
            font-size: 12px;
            margin-top: 5px;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 日志管理系统</h1>
        </div>
        
        <div class="tabs">
            <button class="tab <?php echo $action === '' || $action === 'home' ? 'active' : ''; ?>" onclick="window.location.href='?admin=<?php echo $adminPassword; ?>'">🏠 首页</button>
            <button class="tab <?php echo $action === 'list' ? 'active' : ''; ?>" onclick="window.location.href='?admin=<?php echo $adminPassword; ?>&action=list'">📁 简易信息</button>
            <button class="tab <?php echo $action === 'detailed' ? 'active' : ''; ?>" onclick="window.location.href='?admin=<?php echo $adminPassword; ?>&action=detailed'">📄 详细信息</button>
            <button class="tab <?php echo $action === 'stats' ? 'active' : ''; ?>" onclick="window.location.href='?admin=<?php echo $adminPassword; ?>&action=stats'">📊 统计信息</button>
        </div>
        
        <div class="content-area">
            <?php
            if ($action === 'view' && $viewFile) {
                // 查看单个文件内容
                $filePath = urldecode($viewFile);
                $backAction = 'list';
                $backUrl = '?admin=' . $adminPassword . '&action=' . $backAction;
                
                // 根据文件类型判断返回的标签页
                if (strpos($filePath, 'stats_') !== false) {
                    $backAction = 'stats';
                    $backUrl = '?admin=' . $adminPassword . '&action=' . $backAction;
                } elseif (is_dir(dirname($filePath)) && strpos($filePath, '/') !== false && strpos(dirname($filePath), $rootIpFolder) !== false) {
                    // 检查是否在日期文件夹中（详细信息）
                    $parentDir = basename(dirname($filePath));
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $parentDir)) {
                        $backAction = 'detailed';
                        $backUrl = '?admin=' . $adminPassword . '&action=' . $backAction . '&date=' . urlencode($parentDir);
                    }
                }
                
                if (file_exists($filePath) && strpos(realpath($filePath), realpath($rootIpFolder)) !== false) {
                    echo '<div class="back-btn"><a href="' . $backUrl . '" class="btn btn-secondary">← 返回列表</a></div>';
                    echo '<h3 style="margin-bottom: 15px;">文件：' . htmlspecialchars(basename($filePath)) . '</h3>';
                    echo '<div class="log-content">' . htmlspecialchars(file_get_contents($filePath)) . '</div>';
                } else {
                    echo '<p style="color: #ff4d4f;">文件不存在或无权访问</p>';
                }
            } elseif ($action === 'stats') {
                // 显示统计文件列表
                $statsFiles = glob($rootIpFolder . '/stats_*.txt');
                
                if (empty($statsFiles)) {
                    echo '<p style="color: #8c8c8c;">暂无统计文件</p>';
                } else {
                    // 总体统计
                    $allStatsData = [
                        'total_visits' => 0,
                        'total_unique_ips' => 0,
                        'total_repeat_visits' => 0,
                        'date_count' => count($statsFiles)
                    ];
                    
                    foreach ($statsFiles as $statsFile) {
                        $content = @file_get_contents($statsFile);
                        if ($content) {
                            if (preg_match('/总访问次数：(\d+)/', $content, $matches)) {
                                $allStatsData['total_visits'] += (int)$matches[1];
                            }
                            if (preg_match('/独立IP数量：(\d+)/', $content, $matches)) {
                                $allStatsData['total_unique_ips'] = max($allStatsData['total_unique_ips'], (int)$matches[1]);
                            }
                            if (preg_match('/重复访问次数：(\d+)/', $content, $matches)) {
                                $allStatsData['total_repeat_visits'] += (int)$matches[1];
                            }
                        }
                    }
                    
                    echo '<div class="stats">';
                    echo '<div class="stat-card"><h3>统计日期数量</h3><div class="value">' . $allStatsData['date_count'] . '</div></div>';
                    echo '<div class="stat-card"><h3>累计访问次数</h3><div class="value">' . $allStatsData['total_visits'] . '</div></div>';
                    echo '<div class="stat-card"><h3>累计重复访问</h3><div class="value">' . $allStatsData['total_repeat_visits'] . '</div></div>';
                    echo '</div>';
                    
                    // 文件列表（按日期倒序）
                    usort($statsFiles, function($a, $b) {
                        return filemtime($b) - filemtime($a);
                    });
                    
                    echo '<ul class="file-list">';
                    foreach ($statsFiles as $file) {
                        $fileName = basename($file);
                        $fileSize = filesize($file);
                        $fileTime = date('Y-m-d H:i:s', filemtime($file));
                        $sizeKB = round($fileSize / 1024, 2);
                        
                        // 提取日期
                        $dateMatch = '';
                        if (preg_match('/stats_(\d{4}-\d{2}-\d{2})\.txt/', $fileName, $matches)) {
                            $dateMatch = $matches[1];
                        }
                        
                        // 读取统计内容显示简要信息
                        $statsPreview = '';
                        $content = @file_get_contents($file);
                        if ($content) {
                            if (preg_match('/总访问次数：(\d+)/', $content, $m)) {
                                $statsPreview .= '访问:' . $m[1] . ' ';
                            }
                            if (preg_match('/独立IP数量：(\d+)/', $content, $m)) {
                                $statsPreview .= 'IP:' . $m[1] . ' ';
                            }
                            if (preg_match('/重复访问次数：(\d+)/', $content, $m)) {
                                $statsPreview .= '重复:' . $m[1];
                            }
                        }
                        
                        echo '<li class="file-item">';
                        echo '<div>';
                        echo '<span class="file-name">📊 ' . htmlspecialchars($dateMatch ?: $fileName) . '</span>';
                        echo '<span class="file-size">(' . $sizeKB . ' KB)</span>';
                        if ($statsPreview) {
                            echo '<span class="file-size" style="margin-left: 15px; color: #1890ff;">' . $statsPreview . '</span>';
                        }
                        echo '</div>';
                        echo '<div>';
                        echo '<span class="file-date">' . $fileTime . '</span>';
                        echo ' <a href="?admin=' . $adminPassword . '&action=view&file=' . urlencode($file) . '" class="btn">查看</a>';
                        echo '</div>';
                        echo '</li>';
                    }
                    echo '</ul>';
                }
            } elseif ($action === 'detailed') {
                // 获取所有日期文件夹
                $dateFolders = [];
                if (is_dir($rootIpFolder)) {
                    $items = scandir($rootIpFolder);
                    foreach ($items as $item) {
                        if ($item !== '.' && $item !== '..' && is_dir($rootIpFolder . '/' . $item)) {
                            // 只包含符合日期格式的文件夹（YYYY-MM-DD）
                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $item)) {
                                $dateFolders[] = $item;
                            }
                        }
                    }
                }
                // 按日期倒序排序
                rsort($dateFolders);
                
                // 获取选中的日期
                $selectedDate = $_GET['date'] ?? '';
                
                if (empty($dateFolders)) {
                    echo '<p style="color: #8c8c8c;">暂无详细信息文件</p>';
                } else {
                    // 显示日期选择器
                    echo '<div class="date-selector">';
                    echo '<h3>📅 选择日期查看详细信息</h3>';
                    echo '<div class="date-list">';
                    
                    foreach ($dateFolders as $dateFolder) {
                        $folderPath = $rootIpFolder . '/' . $dateFolder;
                        $files = glob($folderPath . '/*.txt');
                        $fileCount = count($files);
                        $isActive = ($selectedDate === $dateFolder);
                        
                        echo '<a href="?admin=' . $adminPassword . '&action=detailed&date=' . urlencode($dateFolder) . '" class="date-item' . ($isActive ? ' active' : '') . '">';
                        echo '<div class="date-text">' . htmlspecialchars($dateFolder) . '</div>';
                        echo '<div class="file-count">' . $fileCount . ' 个文件</div>';
                        echo '</a>';
                    }
                    
                    echo '</div>';
                    echo '</div>';
                    
                    // 如果选择了日期，显示该日期的文件列表
                    if ($selectedDate && in_array($selectedDate, $dateFolders)) {
                        $folderPath = $rootIpFolder . '/' . $selectedDate;
                        $files = glob($folderPath . '/*.txt');
                        
                        echo '<div class="date-folder">';
                        echo '<h3>📅 ' . htmlspecialchars($selectedDate) . ' 的详细记录 (' . count($files) . ' 个文件)</h3>';
                        
                        if (!empty($files)) {
                            echo '<ul class="file-list">';
                            
                            usort($files, function($a, $b) {
                                return filemtime($b) - filemtime($a); // 按修改时间倒序
                            });
                            
                            foreach ($files as $file) {
                                $fileName = basename($file);
                                $fileSize = filesize($file);
                                $fileTime = date('Y-m-d H:i:s', filemtime($file));
                                $sizeKB = round($fileSize / 1024, 2);
                                
                                echo '<li class="file-item">';
                                echo '<div>';
                                echo '<span class="file-name">' . htmlspecialchars($fileName) . '</span>';
                                echo '<span class="file-size">(' . $sizeKB . ' KB)</span>';
                                echo '</div>';
                                echo '<div>';
                                echo '<span class="file-date">' . $fileTime . '</span>';
                                echo ' <a href="?admin=' . $adminPassword . '&action=view&file=' . urlencode($file) . '" class="btn">查看</a>';
                                echo '</div>';
                                echo '</li>';
                            }
                            
                            echo '</ul>';
                        } else {
                            echo '<p style="color: #8c8c8c; padding: 10px 0;">该日期暂无详细记录文件</p>';
                        }
                        
                        echo '</div>';
                    } elseif ($selectedDate) {
                        echo '<p style="color: #ff4d4f; padding: 10px 0;">选择的日期无效</p>';
                    } else {
                        echo '<p style="color: #8c8c8c; padding: 10px 0;">请选择一个日期查看详细信息</p>';
                    }
                }
            } elseif ($action === '' || $action === 'home') {
                // 首页：显示数据统计
                $today = date('Y-m-d');
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                
                // 读取今日统计
                $todayStatsFile = $rootIpFolder . '/stats_' . $today . '.txt';
                $todayStats = [
                    'total_visits' => 0,
                    'unique_ips' => 0,
                    'repeat_visits' => 0,
                    'new_visits' => 0
                ];
                if (file_exists($todayStatsFile)) {
                    // 优先从JSON文件读取
                    $jsonFile = $todayStatsFile . '.json';
                    if (file_exists($jsonFile)) {
                        $jsonData = @json_decode(file_get_contents($jsonFile), true);
                        if ($jsonData && is_array($jsonData)) {
                            $todayStats['total_visits'] = isset($jsonData['total_visits']) ? (int)$jsonData['total_visits'] : 0;
                            $todayStats['unique_ips'] = isset($jsonData['unique_ips']) ? count($jsonData['unique_ips']) : 0;
                            $todayStats['repeat_visits'] = isset($jsonData['repeat_visits']) ? (int)$jsonData['repeat_visits'] : 0;
                            $todayStats['new_visits'] = $todayStats['total_visits'] - $todayStats['repeat_visits'];
                        }
                    } else {
                        // 从文本文件解析
                        $content = @file_get_contents($todayStatsFile);
                        if ($content) {
                            if (preg_match('/总访问次数：(\d+)/', $content, $m)) $todayStats['total_visits'] = (int)$m[1];
                            if (preg_match('/独立IP数量：(\d+)/', $content, $m)) $todayStats['unique_ips'] = (int)$m[1];
                            if (preg_match('/重复访问次数：(\d+)/', $content, $m)) $todayStats['repeat_visits'] = (int)$m[1];
                            if (preg_match('/新访问次数：(\d+)/', $content, $m)) $todayStats['new_visits'] = (int)$m[1];
                        }
                    }
                }
                
                // 读取昨日统计
                $yesterdayStatsFile = $rootIpFolder . '/stats_' . $yesterday . '.txt';
                $yesterdayStats = [
                    'total_visits' => 0,
                    'unique_ips' => 0,
                    'repeat_visits' => 0,
                    'new_visits' => 0
                ];
                if (file_exists($yesterdayStatsFile)) {
                    // 优先从JSON文件读取
                    $jsonFile = $yesterdayStatsFile . '.json';
                    if (file_exists($jsonFile)) {
                        $jsonData = @json_decode(file_get_contents($jsonFile), true);
                        if ($jsonData && is_array($jsonData)) {
                            $yesterdayStats['total_visits'] = isset($jsonData['total_visits']) ? (int)$jsonData['total_visits'] : 0;
                            $yesterdayStats['unique_ips'] = isset($jsonData['unique_ips']) ? count($jsonData['unique_ips']) : 0;
                            $yesterdayStats['repeat_visits'] = isset($jsonData['repeat_visits']) ? (int)$jsonData['repeat_visits'] : 0;
                            $yesterdayStats['new_visits'] = $yesterdayStats['total_visits'] - $yesterdayStats['repeat_visits'];
                        }
                    } else {
                        // 从文本文件解析
                        $content = @file_get_contents($yesterdayStatsFile);
                        if ($content) {
                            if (preg_match('/总访问次数：(\d+)/', $content, $m)) $yesterdayStats['total_visits'] = (int)$m[1];
                            if (preg_match('/独立IP数量：(\d+)/', $content, $m)) $yesterdayStats['unique_ips'] = (int)$m[1];
                            if (preg_match('/重复访问次数：(\d+)/', $content, $m)) $yesterdayStats['repeat_visits'] = (int)$m[1];
                            if (preg_match('/新访问次数：(\d+)/', $content, $m)) $yesterdayStats['new_visits'] = (int)$m[1];
                        }
                    }
                }
                
                // 计算总数据
                $allStatsFiles = glob($rootIpFolder . '/stats_*.txt');
                $totalStats = [
                    'total_visits' => 0,
                    'total_unique_ips' => [],
                    'total_repeat_visits' => 0,
                    'total_days' => count($allStatsFiles)
                ];
                foreach ($allStatsFiles as $statsFile) {
                    $content = @file_get_contents($statsFile);
                    if ($content) {
                        if (preg_match('/总访问次数：(\d+)/', $content, $m)) {
                            $totalStats['total_visits'] += (int)$m[1];
                        }
                        if (preg_match('/重复访问次数：(\d+)/', $content, $m)) {
                            $totalStats['total_repeat_visits'] += (int)$m[1];
                        }
                        // 提取IP列表
                        if (preg_match_all('/- ([\d\.]+)/', $content, $matches)) {
                            foreach ($matches[1] as $ip) {
                                $totalStats['total_unique_ips'][$ip] = true;
                            }
                        }
                    }
                }
                $totalStats['total_unique_ips_count'] = count($totalStats['total_unique_ips']);
                
                // 显示详细统计信息
                echo '<div class="stats" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">';
                echo '<div class="stat-card" style="background: linear-gradient(135deg, #52c41a 0%, #389e0d 100%);">';
                echo '<h3>📅 今日访问</h3>';
                echo '<div class="value">' . $todayStats['total_visits'] . '</div>';
                echo '<div style="font-size: 12px; margin-top: 5px; opacity: 0.9;">独立IP: ' . $todayStats['unique_ips'] . '</div>';
                echo '</div>';
                
                echo '<div class="stat-card" style="background: linear-gradient(135deg, #1890ff 0%, #096dd9 100%);">';
                echo '<h3>📅 昨日访问</h3>';
                echo '<div class="value">' . $yesterdayStats['total_visits'] . '</div>';
                echo '<div style="font-size: 12px; margin-top: 5px; opacity: 0.9;">独立IP: ' . $yesterdayStats['unique_ips'] . '</div>';
                echo '</div>';
                
                echo '<div class="stat-card" style="background: linear-gradient(135deg, #722ed1 0%, #531dab 100%);">';
                echo '<h3>📊 累计访问</h3>';
                echo '<div class="value">' . $totalStats['total_visits'] . '</div>';
                echo '<div style="font-size: 12px; margin-top: 5px; opacity: 0.9;">累计IP: ' . $totalStats['total_unique_ips_count'] . '</div>';
                echo '</div>';
                
                echo '<div class="stat-card" style="background: linear-gradient(135deg, #fa8c16 0%, #d46b08 100%);">';
                echo '<h3>📈 统计天数</h3>';
                echo '<div class="value">' . $totalStats['total_days'] . '</div>';
                echo '<div style="font-size: 12px; margin-top: 5px; opacity: 0.9;">累计重复: ' . $totalStats['total_repeat_visits'] . '</div>';
                echo '</div>';
                echo '</div>';
                
                // 显示详细数据表格
                echo '<div style="background: #fff; border: 1px solid #e8e8e8; border-radius: 4px; padding: 20px; margin-bottom: 20px;">';
                echo '<h3 style="color: #262626; margin-bottom: 15px; font-size: 16px;">📊 详细数据统计</h3>';
                echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">';
                
                // 今日详细数据
                echo '<div style="border: 1px solid #e8e8e8; border-radius: 4px; padding: 15px;">';
                echo '<h4 style="color: #52c41a; margin-bottom: 10px; font-size: 14px;">📅 今日 (' . $today . ')</h4>';
                echo '<div style="font-size: 13px; line-height: 1.8; color: #595959;">';
                echo '<div>总访问次数: <strong style="color: #262626;">' . $todayStats['total_visits'] . '</strong></div>';
                echo '<div>独立IP数量: <strong style="color: #262626;">' . $todayStats['unique_ips'] . '</strong></div>';
                echo '<div>新访问次数: <strong style="color: #262626;">' . $todayStats['new_visits'] . '</strong></div>';
                echo '<div>重复访问次数: <strong style="color: #262626;">' . $todayStats['repeat_visits'] . '</strong></div>';
                echo '</div>';
                echo '</div>';
                
                // 昨日详细数据
                echo '<div style="border: 1px solid #e8e8e8; border-radius: 4px; padding: 15px;">';
                echo '<h4 style="color: #1890ff; margin-bottom: 10px; font-size: 14px;">📅 昨日 (' . $yesterday . ')</h4>';
                echo '<div style="font-size: 13px; line-height: 1.8; color: #595959;">';
                echo '<div>总访问次数: <strong style="color: #262626;">' . $yesterdayStats['total_visits'] . '</strong></div>';
                echo '<div>独立IP数量: <strong style="color: #262626;">' . $yesterdayStats['unique_ips'] . '</strong></div>';
                echo '<div>新访问次数: <strong style="color: #262626;">' . $yesterdayStats['new_visits'] . '</strong></div>';
                echo '<div>重复访问次数: <strong style="color: #262626;">' . $yesterdayStats['repeat_visits'] . '</strong></div>';
                echo '</div>';
                echo '</div>';
                
                // 总数据
                echo '<div style="border: 1px solid #e8e8e8; border-radius: 4px; padding: 15px;">';
                echo '<h4 style="color: #722ed1; margin-bottom: 10px; font-size: 14px;">📊 累计总数据</h4>';
                echo '<div style="font-size: 13px; line-height: 1.8; color: #595959;">';
                echo '<div>累计访问次数: <strong style="color: #262626;">' . $totalStats['total_visits'] . '</strong></div>';
                echo '<div>累计独立IP: <strong style="color: #262626;">' . $totalStats['total_unique_ips_count'] . '</strong></div>';
                echo '<div>累计重复访问: <strong style="color: #262626;">' . $totalStats['total_repeat_visits'] . '</strong></div>';
                echo '<div>统计天数: <strong style="color: #262626;">' . $totalStats['total_days'] . '</strong></div>';
                $avgVisits = $totalStats['total_days'] > 0 ? round($totalStats['total_visits'] / $totalStats['total_days'], 2) : 0;
                echo '<div>日均访问: <strong style="color: #262626;">' . $avgVisits . '</strong></div>';
                echo '</div>';
                echo '</div>';
                
                echo '</div>';
                echo '</div>';
            } elseif ($action === 'list') {
                // 简易信息标签页：显示简短日志文件列表
                $simpleLogFiles = glob($rootIpFolder . '/access_simple_*.txt');
                
                if (empty($simpleLogFiles)) {
                    echo '<p style="color: #8c8c8c;">暂无简短日志文件</p>';
                } else {
                    // 统计信息
                    $totalFiles = count($simpleLogFiles);
                    $totalSize = 0;
                    foreach ($simpleLogFiles as $file) {
                        $totalSize += filesize($file);
                    }
                    
                    echo '<div class="stats">';
                    echo '<div class="stat-card"><h3>日志文件总数</h3><div class="value">' . $totalFiles . '</div></div>';
                    echo '<div class="stat-card"><h3>总文件大小</h3><div class="value">' . round($totalSize / 1024, 2) . ' KB</div></div>';
                    echo '</div>';
                    
                    // 文件列表
                    usort($simpleLogFiles, function($a, $b) {
                        return filemtime($b) - filemtime($a); // 按修改时间倒序
                    });
                    
                    echo '<ul class="file-list">';
                    foreach ($simpleLogFiles as $file) {
                        $fileName = basename($file);
                        $fileSize = filesize($file);
                        $fileTime = date('Y-m-d H:i:s', filemtime($file));
                        $sizeKB = round($fileSize / 1024, 2);
                        
                        // 统计该文件的行数
                        $lineCount = 0;
                        if ($handle = fopen($file, 'r')) {
                            while (!feof($handle)) {
                                fgets($handle);
                                $lineCount++;
                            }
                            fclose($handle);
                        }
                        
                        echo '<li class="file-item">';
                        echo '<div>';
                        echo '<span class="file-name">' . htmlspecialchars($fileName) . '</span>';
                        echo '<span class="file-size">(' . $sizeKB . ' KB, ' . $lineCount . ' 行)</span>';
                        echo '</div>';
                        echo '<div>';
                        echo '<span class="file-date">' . $fileTime . '</span>';
                        echo ' <a href="?admin=' . $adminPassword . '&action=view&file=' . urlencode($file) . '" class="btn">查看</a>';
                        echo '</div>';
                        echo '</li>';
                    }
                    echo '</ul>';
                }
            }
            ?>
        </div>
    </div>
</body>
</html>
    <?php
    exit; // 退出，不执行后续的正常流程
}

// 步骤12：超强恐吓提示页面（保留原有视觉威慑效果，包含JavaScript客户端信息收集）
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网络安全预警系统 - 违规行为告警通知</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
            body {
            background: #f5f5f5;
            font-family: "Microsoft YaHei", "SimHei", "PingFang SC", "Helvetica Neue", Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 20px;
            color: #333;
            }
        .container {
            background: #fff;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            max-width: 900px;
            width: 100%;
            margin: 20px 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
        .header {
            background: linear-gradient(135deg, #1890ff 0%, #096dd9 100%);
            color: #fff;
            padding: 20px 30px;
            border-radius: 4px 4px 0 0;
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
                margin-bottom: 10px;
            }
        .system-name {
            font-size: 20px;
            font-weight: 500;
            letter-spacing: 1px;
        }
        .case-number {
            font-size: 12px;
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 12px;
        }
        .header-subtitle {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 5px;
        }
        .status-bar {
            background: #fff1f0;
            border-left: 4px solid #ff4d4f;
            padding: 12px 20px;
            margin: 20px 30px;
            border-radius: 2px;
            display: flex;
            align-items: center;
        }
        .status-icon {
            width: 16px;
            height: 16px;
            background: #ff4d4f;
            border-radius: 50%;
            margin-right: 10px;
            animation: statusBlink 2s infinite;
        }
        @keyframes statusBlink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
            }
        .status-text {
            color: #ff4d4f;
            font-weight: 500;
            font-size: 14px;
        }
        .content {
            padding: 0 30px 30px;
        }
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #262626;
            margin: 25px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e8e8e8;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
                margin-bottom: 20px;
            }
        .info-table tr {
            border-bottom: 1px solid #f0f0f0;
            }
        .info-table td {
            padding: 12px 0;
                font-size: 14px;
                line-height: 1.6;
            }
            .info-label {
            color: #595959;
            width: 140px;
            font-weight: 500;
            }
            .info-value {
            color: #262626;
            word-break: break-word;
            }
        .badge {
            display: inline-block;
            background: #fff1f0;
            color: #cf1322;
            border: 1px solid #ffccc7;
            padding: 2px 8px;
            border-radius: 2px;
            font-size: 12px;
            margin-left: 8px;
        }
        .warning-box {
            background: #fff7e6;
            border: 1px solid #ffe58f;
            border-left: 4px solid #faad14;
            padding: 18px 20px;
                margin: 20px 0;
            border-radius: 2px;
            }
        .warning-title {
            color: #d48806;
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            }
        .warning-icon {
                margin-right: 8px;
            font-size: 18px;
            }
        .warning-content {
            color: #595959;
            font-size: 14px;
            line-height: 37px;
        }
        .warning-content p {
            line-height: 37px;
        }
        .warning-list {
            margin: 10px 0;
            padding-left: 20px;
        }
        .warning-list li {
            margin: 8px 0;
            color: #595959;
            line-height: 37px;
        }
        .date-signature {
            text-align: right;
                margin-top: 20px;
            padding-right: 40px;
            font-size: 14px;
            line-height: 37px;
            }
        .footer {
            background: #fafafa;
            border-top: 1px solid #e8e8e8;
            padding: 20px 30px;
            margin-top: 30px;
            border-radius: 0 0 4px 4px;
            font-size: 12px;
            color: #8c8c8c;
            line-height: 1.8;
        }
        .footer-title {
            font-weight: 600;
            color: #595959;
            margin-bottom: 8px;
        }
        .footer-info {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .footer-item {
            margin: 5px 0;
            }
        .vpn-alert {
            color: #cf1322;
            font-weight: 500;
        }
        @media (max-width: 768px) {
            body {
                padding: 8px;
                font-size: 14px;
            }
            .container {
                margin: 8px 0;
                border-radius: 4px;
            }
            .header {
                padding: 12px 15px;
            }
            .header-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .system-name {
                font-size: 16px;
                letter-spacing: 0.5px;
            }
            .case-number {
                font-size: 11px;
                padding: 3px 10px;
                align-self: flex-start;
                word-break: break-all;
                line-height: 1.4;
            }
            .header-subtitle {
                font-size: 11px;
                margin-top: 4px;
            }
            .status-bar {
                padding: 10px 15px;
                margin: 15px 15px;
            }
            .status-icon {
                width: 12px;
                height: 12px;
                margin-right: 8px;
            }
            .status-text {
                font-size: 12px;
                line-height: 1.5;
            }
            .content {
                padding: 0 15px 15px;
            }
            .section-title {
                font-size: 15px;
                margin: 20px 0 12px 0;
            }
            .info-table {
                margin-bottom: 15px;
            }
            .info-table td {
                display: block;
                padding: 10px 0;
                border-bottom: 1px solid #f0f0f0;
            }
            .info-table tr:last-child td {
                border-bottom: none;
            }
            .info-label {
                width: 100%;
                margin-bottom: 6px;
                font-size: 13px;
                color: #595959;
            }
            .info-value {
                font-size: 13px;
                line-height: 1.6;
                word-break: break-word;
            }
            .badge {
                font-size: 11px;
                padding: 2px 6px;
                margin-left: 6px;
                display: inline-block;
            }
            .warning-box {
                padding: 15px 15px;
                margin: 15px 0;
            }
            .warning-title {
                font-size: 14px;
                margin-bottom: 10px;
                flex-wrap: wrap;
            }
            .warning-icon {
                font-size: 16px;
                margin-right: 6px;
            }
            .warning-content {
                font-size: 13px;
                line-height: 1.8;
            }
            .warning-content p {
                font-size: 13px;
                line-height: 1.8;
                margin-bottom: 10px;
            }
            .warning-list {
                margin: 8px 0;
                padding-left: 18px;
            }
            .warning-list li {
                margin: 6px 0;
                font-size: 13px;
                line-height: 1.8;
            }
            .date-signature {
                text-align: right;
                margin-top: 15px;
                padding-right: 15px;
                font-size: 13px;
                line-height: 1.8;
            }
            .footer {
                padding: 15px 15px;
                margin-top: 20px;
                font-size: 11px;
            }
            .footer-title {
                font-size: 12px;
                margin-bottom: 6px;
            }
            .footer-info {
                flex-direction: column;
                gap: 5px;
            }
            .footer-item {
                margin: 3px 0;
                font-size: 11px;
            }
            .vpn-alert {
                font-size: 13px;
                line-height: 1.6;
            }
        }
        @media (max-width: 480px) {
            body {
                padding: 5px;
            }
            .container {
                margin: 5px 0;
            }
            .header {
                padding: 10px 12px;
            }
            .system-name {
                font-size: 15px;
            }
            .case-number {
                font-size: 10px;
                padding: 2px 8px;
                line-height: 1.3;
            }
            .status-bar {
                padding: 8px 12px;
                margin: 12px 10px;
            }
            .status-text {
                font-size: 11px;
            }
            .content {
                padding: 0 12px 12px;
            }
            .section-title {
                font-size: 14px;
                margin: 15px 0 10px 0;
            }
            .info-label {
                font-size: 12px;
            }
            .info-value {
                font-size: 12px;
            }
            .warning-box {
                padding: 12px 12px;
            }
            .warning-title {
                font-size: 13px;
            }
            .warning-content {
                font-size: 12px;
            }
            .warning-content p {
                font-size: 12px;
            }
            .warning-list li {
                font-size: 12px;
            }
            .date-signature {
                font-size: 12px;
                padding-right: 12px;
            }
            .footer {
                padding: 12px 12px;
                font-size: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-top">
                <div class="system-name">网络安全预警系统</div>
                <div class="case-number">案件编号：国网安罚决字〔<?php echo date('Y'); ?>〕<?php echo date('Hi'); ?>号</div>
            </div>
            <div class="header-subtitle">Network Security Early Warning System</div>
        </div>
        
        <div class="status-bar">
            <div class="status-icon"></div>
            <div class="status-text">检测到异常访问行为，系统已自动记录相关信息</div>
            </div>
        
        <div class="content">
            <div class="section-title">访问信息记录</div>
            <table class="info-table">
                <tr>
                    <td class="info-label">访问时间</td>
                    <td class="info-value"><?php echo $accessTime; ?> <span class="badge">已记录</span></td>
                </tr>
                <tr>
                    <td class="info-label">客户端IP地址</td>
                    <td class="info-value"><?php echo htmlspecialchars($clientIp); ?> <span class="badge">已标记</span></td>
                </tr>
                <tr>
                    <td class="info-label">地理位置信息</td>
                    <td class="info-value"><?php echo htmlspecialchars($ipLocation); ?></td>
                </tr>
            <?php if ($vpnDetection['is_vpn'] || $vpnDetection['is_proxy'] || $vpnDetection['is_tor']): ?>
                <tr>
                    <td class="info-label">网络代理检测</td>
                    <td class="info-value vpn-alert">
                    <?php
                    $vpnAlert = [];
                    if ($vpnDetection['is_vpn']) {
                            $vpnAlert[] = '检测到VPN服务：' . htmlspecialchars($vpnDetection['vpn_type']);
                    }
                    if ($vpnDetection['is_proxy']) {
                            $vpnAlert[] = '检测到代理服务：' . htmlspecialchars($vpnDetection['proxy_type']);
                    }
                    if ($vpnDetection['is_tor']) {
                            $vpnAlert[] = '检测到Tor匿名网络';
                    }
                    echo implode(' | ', $vpnAlert);
                    ?>
                    <?php if ($vpnDetection['possible_source_ip'] !== $clientIp): ?>
                        <br style="margin-top: 8px;"><span style="font-size: 12px; color: #8c8c8c;">真实源IP溯源：<?php echo htmlspecialchars($vpnDetection['possible_source_ip']); ?></span>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            </table>
        
            <div class="warning-box">
                <div class="warning-title">
                    <span class="warning-icon">⚠</span>
                    重要提示
            </div>
                <div class="warning-content">
                    <p style="margin-bottom: 12px;">根据《中华人民共和国网络安全法》及相关法律法规规定，您的访问行为已被系统记录并备案。</p>
                    <ul class="warning-list">
                        <li>您的IP地址已同步上传至系统备案库</li>
                        <li>设备硬件指纹、浏览器特征等信息已采集并归档</li>
                        <li>访问时间、访问轨迹等行为数据已生成完整记录</li>
                        <li>所有数据已加密存储至安全服务器，作为法律证据保存</li>
                    </ul>
                    <p style="margin-top: 15px; font-weight: 500; color: #cf1322;">
                        如对本次访问记录有疑问，请在24小时内联系系统管理员进行申诉。逾期将按照相关规定处理。
                    </p>
                </div>
            </div>
            
            <div class="date-signature">
            <div class="footer-item">案号：<?php echo $browserFingerprint; ?></div><?php echo $chineseDate; ?>
        </div>
        
            <div class="footer">
                <div class="footer-title">系统说明</div>
                <p>本系统已启用安全监控机制，所有访问行为均被实时记录。系统采用加密存储技术，确保数据安全性和完整性。</p>
            </div>
        </div>
    </div>
    
    <script>
    // 收集客户端详细信息（屏幕分辨率、本地存储等）
    (function() {
        try {
            var clientInfo = {};
            
            // 1. 屏幕信息
            if (window.screen) {
                clientInfo.screen = {
                    width: window.screen.width || '未知',
                    height: window.screen.height || '未知',
                    availWidth: window.screen.availWidth || '未知',
                    availHeight: window.screen.availHeight || '未知',
                    colorDepth: window.screen.colorDepth || '未知',
                    pixelDepth: window.screen.pixelDepth || '未知'
                };
            }
            
            // 2. 窗口信息
            if (window.innerWidth) {
                clientInfo.window = {
                    width: window.innerWidth || '未知',
                    height: window.innerHeight || '未知',
                    outerWidth: window.outerWidth || '未知',
                    outerHeight: window.outerHeight || '未知'
                };
            }
            
            // 3. 时区信息
            if (Intl && Intl.DateTimeFormat) {
                try {
                    var timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '未知';
                    var offset = new Date().getTimezoneOffset();
                    var offsetHours = Math.abs(Math.floor(offset / 60));
                    var offsetMinutes = Math.abs(offset % 60);
                    var offsetStr = (offset <= 0 ? '+' : '-') + 
                                   String(offsetHours).padStart(2, '0') + ':' + 
                                   String(offsetMinutes).padStart(2, '0');
                    clientInfo.timezone = timezone + ' (UTC' + offsetStr + ')';
                } catch(e) {
                    clientInfo.timezone = '获取失败';
                }
            }
            
            // 4. 本地存储和会话存储（自身域名）
            clientInfo.storage = {
                localStorage: {},
                sessionStorage: {}
            };
            
            try {
                if (typeof(Storage) !== 'undefined') {
                    // 收集localStorage
                    if (window.localStorage) {
                        for (var i = 0; i < localStorage.length; i++) {
                            var key = localStorage.key(i);
                            try {
                                clientInfo.storage.localStorage[key] = localStorage.getItem(key);
                            } catch(e) {
                                clientInfo.storage.localStorage[key] = '[无法读取]';
                            }
                        }
                    }
                    
                    // 收集sessionStorage
                    if (window.sessionStorage) {
                        for (var i = 0; i < sessionStorage.length; i++) {
                            var key = sessionStorage.key(i);
                            try {
                                clientInfo.storage.sessionStorage[key] = sessionStorage.getItem(key);
                            } catch(e) {
                                clientInfo.storage.sessionStorage[key] = '[无法读取]';
                            }
                        }
                    }
                }
            } catch(e) {
                clientInfo.storage = {error: '无法访问存储'};
            }
            
            // 5. 硬件信息（部分浏览器支持）
            clientInfo.hardware = {};
            if (navigator.hardwareConcurrency) {
                clientInfo.hardware.cpuCores = navigator.hardwareConcurrency + ' 核心';
            }
            if (navigator.deviceMemory) {
                clientInfo.hardware.memory = navigator.deviceMemory + ' GB';
            }
            
            // 6. 浏览器语言详细信息
            if (navigator.language) {
                clientInfo.languages = navigator.languages || [navigator.language];
            }
            
            // 7. 平台信息
            if (navigator.platform) {
                clientInfo.platform = navigator.platform;
            }
            
            // 8. 在线状态
            clientInfo.onLine = navigator.onLine ? '在线' : '离线';
            
            // 9. Cookie（JavaScript读取，但PHP已有，这里作为补充）
            if (document.cookie) {
                var cookies = {};
                document.cookie.split(';').forEach(function(cookie) {
                    var parts = cookie.trim().split('=');
                    if (parts.length >= 2) {
                        cookies[parts[0]] = parts.slice(1).join('=');
                    }
                });
                clientInfo.cookies = cookies;
            }
            
            // 10. 页面URL信息
            clientInfo.url = {
                href: window.location.href,
                origin: window.location.origin,
                pathname: window.location.pathname,
                search: window.location.search,
                hash: window.location.hash
            };
            
            // 将收集的信息发送回服务器（使用隐藏表单提交或fetch）
            if (clientInfo && Object.keys(clientInfo).length > 0) {
                // 创建隐藏表单并提交
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                form.style.display = 'none';
                
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'js_client_info';
                input.value = JSON.stringify(clientInfo);
                form.appendChild(input);
                
                document.body.appendChild(form);
                
                // 使用fetch异步发送（不刷新页面）
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'js_client_info=' + encodeURIComponent(JSON.stringify(clientInfo))
                }).catch(function(err) {
                    // 静默失败，不影响页面显示
                    console.error('客户端信息发送失败:', err);
                });
            }
        } catch(e) {
            // 静默处理错误，不影响页面显示
            console.error('客户端信息收集失败:', e);
        }
    })();
    </script>
</body>
</html>
