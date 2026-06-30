<?php
class remote_play{
    /**
     * @throws Exception
     */
    function __construct(){
        if( FRONT_END ){
            $this->add_upload_form();
            $this->add_js();
        }
        $this->register_custom_upload_field();
        $this->register_custom_video_file_funcs();
    }

    /**
     * @throws Exception
     */
    private function add_upload_form(): void
    {
        ClipBucket::getInstance()->upload_opt_list['link_video_link'] = [
            'title'      => lang('remote_play'),
            'class'      => self::class,
            'function'   => 'load_form'
        ];
    }

    /**
     * @throws Exception
     */
    private function add_js(): void
    {
        if( defined('THIS_PAGE') && THIS_PAGE != 'upload' ){
            return;
        }

        $min_suffixe = System::isInDev() ? '' : '.min';
        ClipBucket::getInstance()->addJS(['pages/remote_play/remote_play' . $min_suffixe . '.js'  => 'admin']);
        ClipBucket::getInstance()->add_header(LAYOUT .'/blocks/remote_play/header.html');
    }

    /**
     * @throws Exception
     */
    private function register_custom_upload_field(): void
    {
        global $cb_columns;
        $link_vid_field_array['remote_play_url'] = [
            'title'                  => lang('remote_play_input_url'),
            'name'                   => 'remote_play_url',
            'db_field'               => 'remote_play_url',
            'required'               => 'no',
            'validate_function'      => self::class.'::isValidVideoURL',
            'type'                   => 'textfield',
            'keep_original_on_error' => true
        ];

        register_custom_upload_field($link_vid_field_array);

        $cb_columns->object('videos')->add_column('remote_play_url');
        Video::getInstance()->addFields(['remote_play_url']);
    }

    private function register_custom_video_file_funcs(): void
    {
        ClipBucket::getInstance()->register_custom_video_file_func('get_video_url', self::class);
    }

    /**
     * @throws Exception
     */
    public static function load_form(): void
    {
        assign('placeholder_url', lang('remote_play_input_url_example', DirPath::getUrl('videos') . 'example.mp4'));
        Template(LAYOUT . '/blocks/remote_play/first-form.html', false);
    }

    public static function validateRemoteVideoUrl($video_url, bool $probe_video = false, ?string &$error = null): bool
    {
        if( empty($video_url) ){
            $error = lang('remote_play_invalid_url');
            return false;
        }

        if( filter_var($video_url, FILTER_VALIDATE_URL) === false ){
            $error = lang('remote_play_invalid_url');
            return false;
        }

        if( !self::isPublicHttpUrl($video_url, $error) ){
            return false;
        }

        $check_url = @get_headers($video_url);
        if( !isset($check_url[0]) ){
            $error = lang('remote_play_website_not_responding');
            return false;
        }

        if( !preg_match('/^HTTP\/\d(?:\.\d)?\s+2\d\d\b/i', $check_url[0]) ){
            $error = lang('remote_play_url_not_working');
            return false;
        }

        if( $probe_video ){
            require_once DirPath::get('classes') . 'sLog.php';
            $log = new SLog();
            $ffmpeg = new FFMpeg($log);
            $video_infos = $ffmpeg->get_file_info($video_url);

            if( !self::hasVideoStream($video_infos) ){
                $error = lang('remote_play_not_valid_video');
                return false;
            }
        }

        return true;
    }

    private static function isPublicHttpUrl(string $video_url, ?string &$error = null): bool
    {
        $parts = parse_url($video_url);
        if( !$parts || empty($parts['scheme']) || empty($parts['host']) ){
            $error = lang('remote_play_invalid_url');
            return false;
        }

        if( !in_array(strtolower($parts['scheme']), ['http', 'https'], true) ){
            $error = lang('remote_play_invalid_url');
            return false;
        }

        $host = trim($parts['host'], '[]');
        $resolved_ips = self::getResolvedIps($host);
        if( empty($resolved_ips) ){
            $error = lang('remote_play_invalid_url');
            return false;
        }

        foreach($resolved_ips as $ip){
            if( !self::isPublicIp($ip) ){
                $error = lang('remote_play_invalid_url');
                return false;
            }
        }

        return true;
    }

    private static function getResolvedIps(string $host): array
    {
        $resolved_ips = [];

        if( filter_var($host, FILTER_VALIDATE_IP) ){
            return [$host];
        }

        $ipv4 = gethostbyname($host);
        if( $ipv4 !== $host && filter_var($ipv4, FILTER_VALIDATE_IP) ){
            $resolved_ips[] = $ipv4;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if( is_array($records) ){
            foreach($records as $record){
                if( isset($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP) ){
                    $resolved_ips[] = $record['ip'];
                }
                if( isset($record['ipv6']) && filter_var($record['ipv6'], FILTER_VALIDATE_IP) ){
                    $resolved_ips[] = $record['ipv6'];
                }
            }
        }

        return array_unique($resolved_ips);
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function hasVideoStream(array $video_infos): bool
    {
        return !empty($video_infos['format'])
            && !empty($video_infos['video_width'])
            && !empty($video_infos['video_height']);
    }

    /**
     * @throws Exception
     */
    public static function isValidVideoURL($video_url){
        $error = null;
        if( !self::validateRemoteVideoUrl($video_url, false, $error) ){
            e($error);
            return false;
        }

        return $video_url;
    }

    public static function get_video_url($video, $hq = false)
    {
        if( empty($video['remote_play_url']) ) {
            return false;
        }

        if( self::hasLocalVideoFile($video) ){
            return false;
        }

        return $video['remote_play_url'];
    }

    private static function hasLocalVideoFile(array $video): bool
    {
        if( empty($video['file_name']) ){
            return false;
        }

        $file_directory = '';
        if( !empty($video['file_directory']) ){
            $file_directory = $video['file_directory'] . DIRECTORY_SEPARATOR;
        }

        $patterns = [
            DirPath::get('videos') . $file_directory . $video['file_name'] . '*',
            DirPath::get('videos') . $file_directory . $video['file_name'] . DIRECTORY_SEPARATOR . '*'
        ];

        foreach($patterns as $pattern){
            $files = glob($pattern);
            if( !is_array($files) ){
                continue;
            }

            foreach($files as $file){
                if( is_file($file) && filesize($file) > 100 ){
                    return true;
                }
            }
        }

        return false;
    }

    private static function getDownloadExtension(string $video_url, array $video_infos): string
    {
        $path = parse_url($video_url, PHP_URL_PATH) ?: '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if( preg_match('/^[a-z0-9]{2,5}$/', $extension) ){
            return $extension;
        }

        $format = strtolower($video_infos['format'] ?? '');
        if( str_contains($format, 'mp4') ){
            return 'mp4';
        }
        if( str_contains($format, 'webm') ){
            return 'webm';
        }
        if( str_contains($format, 'matroska') ){
            return 'mkv';
        }
        if( str_contains($format, 'mpegts') ){
            return 'ts';
        }
        if( str_contains($format, 'hls') ){
            return 'm3u8';
        }

        return 'video';
    }

    /**
     * @throws Exception
     */
    public static function process_file($video_url, $video_id): void
    {
        require_once DirPath::get('classes') . 'sLog.php';

        $file_directory = create_dated_folder();

        $video = Video::getInstance()->getOne(['videoid' => $video_id]);
        if( empty($video) ){
            return;
        }

        $logFile = DirPath::get('logs') . $file_directory . DIRECTORY_SEPARATOR . $video['file_name'] . '.log';

        $log = new SLog($logFile);
        $ffmpeg = new FFMpeg($log);
        $local_input_file = '';

        try {
            $ffmpeg->log->newSection('Remote video validation');
            $video_infos = $ffmpeg->get_file_info($video_url);
            if( !self::hasVideoStream($video_infos) ){
                throw new Exception(lang('remote_play_not_valid_video'));
            }

            $extension = self::getDownloadExtension($video_url, $video_infos);
            $local_input_file = DirPath::get('temp') . $video['file_name'] . '.' . $extension;

            $ffmpeg->log->newSection('Remote video download');
            $ffmpeg->log->writeLine(date('Y-m-d H:i:s') . ' - Downloading remote video...');
            Network::download_file($video_url, $local_input_file);

            if( !file_exists($local_input_file) || filesize($local_input_file) === 0 ){
                throw new Exception('Remote video download failed');
            }

            update_video_by_filename(
                $video['file_name'],
                ['file_directory', 'file_type', 'status'],
                [$file_directory, config('conversion_type'), 'Waiting']
            );

            $ffmpeg->conversion_type = config('conversion_type');
            $ffmpeg->input_file = $local_input_file;
            $ffmpeg->file_directory = $file_directory . DIRECTORY_SEPARATOR;
            $ffmpeg->file_name = $video['file_name'];
            $ffmpeg->ClipBucket((int)$video_id);

            $fields = ['video_files', 'duration'];
            $values = [json_encode($ffmpeg->video_files), (int)$ffmpeg->input_details['duration']];

            if( Update::IsCurrentDBVersionIsHigherOrEqualTo('5.5.1', '273') && !empty($ffmpeg->input_details['fov']) ){
                $fields[] = 'fov';
                $values[] = $ffmpeg->input_details['fov'];
            }

            if( Update::IsCurrentDBVersionIsHigherOrEqualTo('5.5.1', '279') ){
                $fields[] = 'convert_percent';
                $values[] = 100;
            }

            if( !empty($ffmpeg->video_files) ){
                $fields[] = 'remote_play_url';
                $values[] = '';
            }

            update_video_by_filename($video['file_name'], $fields, $values);

            $videoDetails = CBvideo::getInstance()->get_video($video['file_name'], true);
            update_bits_color($videoDetails);
            update_castable_status($videoDetails);
        } catch (Throwable $e) {
            $ffmpeg->log->newSection('Remote video processing failed');
            $ffmpeg->log->writeLine($e->getMessage());
            update_video_status($video['file_name'], 'Failed');

            if( !empty($local_input_file) && file_exists($local_input_file) ){
                unlink($local_input_file);
            }

            if( !empty($local_input_file) && file_exists($local_input_file . '_ongoing') ){
                unlink($local_input_file . '_ongoing');
            }

            $ffmpeg->unLock();
        }
    }
}
