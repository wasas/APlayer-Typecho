<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 在 Typecho 中使用 APlayer 播放在线音乐吧～
 *
 * @package APlayer for Typecho | Meting
 * @author METO
 * @version 2.1.2
 * @dependence 14.10.10-*
 * @link https://github.com/MoePlayer/APlayer-Typecho
 *
 */

define('METING_VERSION', '2.1.2');

class Meting_Plugin extends Typecho_Widget implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Meting_Plugin::installCheck();
        Helper::addAction('metingapi', 'Meting_Action');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('Meting_Plugin','playerReplace');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('Meting_Plugin','playerReplace');
        Typecho_Plugin::factory('Widget_Archive')->header = array('Meting_Plugin','header');
        Typecho_Plugin::factory('Widget_Archive')->footer = array('Meting_Plugin','footer');
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array('Meting_Plugin', 'addButton');
        Typecho_Plugin::factory('admin/write-page.php')->bottom = array('Meting_Plugin', 'addButton');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        Helper::removeAction("metingapi");
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $list = array(
            'none' => _t('关闭'),
            'redis' => _t('Redis'),
            'memcached' => _t('Memcached'),
            'mysql' => _t('MySQL'),
            'sqlite' => _t('SQLite')
        );
        $t = new Typecho_Widget_Helper_Form_Element_Radio(
            'cachetype',
            $list,
            'none',
            _t('缓存驱动'),
            _t('缓存歌曲解析信息，降低服务器压力')
        );
        $form->addInput($t);
        $t = new Typecho_Widget_Helper_Form_Element_Text(
            'cachehost',
            null,
            '127.0.0.1',
            _t('缓存服务地址'),
            _t('通常为 localhost, 127.0.0.1')
        );
        $form->addInput($t);
        $t = new Typecho_Widget_Helper_Form_Element_Text(
            'cacheport',
            null,
            '6379',
            _t('缓存服务端口'),
            _t('默认端口 memcache: 11211, Redis: 6379, Mysql: 3306')
        );
        $form->addInput($t);

        $t = new Typecho_Widget_Helper_Form_Element_Radio(
            'bitrate',
            array('128' => _t('流畅品质 128K'),'192' => _t('清晰品质 192K'),'320' => _t('高品质 320K')),
            '192',
            _t('默认音质'),
            _t('')
        );
        $form->addInput($t);
        $t = new Typecho_Widget_Helper_Form_Element_Text(
            'api',
            null,
            Typecho_Common::url('action/metingapi', Helper::options()->index)."?server=:server&type=:type&id=:id&auth=:auth&r=:r",
            _t('* 云解析地址'),
            _t('示例：https://api.i-meto.com/meting/api?server=:server&type=:type&id=:id&r=:r')
        );
        $form->addInput($t);
        $t = new Typecho_Widget_Helper_Form_Element_Text(
            'salt',
            null,
            Typecho_Common::randString(32),
            _t('* 接口保护'),
            _t('加盐保护 API 接口不被滥用，自动生成无需设置。')
        );
        $form->addInput($t);
        $t = new Typecho_Widget_Helper_Form_Element_Textarea(
            'cookie',
            null,
            '',
            _t('* 网易云音乐 Cookie'),
            _t('如果您是网易云音乐的会员，可以将您的 cookie 填入此处来获取云盘等付费资源，听歌将不会计入下载次数。<br><b>如果不知道这是什么意思，忽略即可。</b>')
        );
        $form->addInput($t);
    }

    /**
     * 手动保存配置句柄
     * @param $config array 插件配置
     * @param $is_init bool 是否初始化
     */
    public static function configHandle($config, $is_init)
    {
        if (!$is_init) {
            if (empty($config['api'])) {
                $config['api'] = Typecho_Common::url('action/metingapi', Helper::options()->index)."?server=:server&type=:type&id=:id&auth=:auth&r=:r";
            }
            if ($config['cachetype'] != 'none') {
                require_once 'driver/cache.interface.php';
                require_once 'driver/'.$config['cachetype'].'.class.php';
                try {
                    $cache = new MetingCache(array(
                        'host' => $config['cachehost'],
                        'port' => $config['cacheport']
                    ));
                    $cache->install();
                    $cache->check();
                    $cache->flush();
                } catch (Exception $e) {
                    throw new Typecho_Plugin_Exception(_t($e->getMessage()));
                }
            }
        }

        Helper::configPlugin('Meting', $config);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function header()
    {
        echo "<link rel=\"stylesheet\" href=\"//cdn.jsdelivr.net/npm/aplayer/dist/APlayer.min.css\">\n";
    }

    public static function footer()
    {
        $api = Typecho_Widget::widget('Widget_Options')->plugin('Meting')->api;
        echo "<script type=\"text/javascript\" src=\"//cdn.jsdelivr.net/npm/aplayer/dist/APlayer.min.js\"></script>\n";
        echo "<script>var meting_api=\"{$api}\";</script>";
        echo "<script type=\"text/javascript\" src=\"//cdn.jsdelivr.net/npm/meting/dist/Meting.min.js\"></script>\n";
    }

    public static function playerReplace($data, $widget, $last)
    {
        $text = empty($last)?$data:$last;
        if ($widget instanceof Widget_Archive) {
            $data = $text;
            $pattern = self::get_shortcode_regex(array('Meting'));
            $text = preg_replace_callback("/$pattern/", array('Meting_Plugin','parseCallback'), $data);
        }
        return $text;
    }

    public static function parseCallback($matches)
    {
        $setting = self::shortcode_parse_atts(htmlspecialchars_decode($matches[3]));
        $matches[5] = htmlspecialchars_decode($matches[5]);
        $pattern = self::get_shortcode_regex(array('Music'));
        preg_match_all("/$pattern/", $matches[5], $all);
        if (sizeof($all[3])) return Meting_Plugin::parseMusic($all[3], $setting);
    }

    public static function parseMusic($matches, $setting)
    {
        $salt = Typecho_Widget::widget('Widget_Options')->plugin('Meting')->salt;
        $str = '';

        foreach ($matches as $vo) {
            $data = self::shortcode_parse_atts(htmlspecialchars_decode($vo));
            $str .= "\n<meting-js ";
            foreach ($data as $key => $value) $str .= "{$key}=\"{$value}\" ";
            if (isset($data['server'])) {
                $auth = md5($salt.$data['server'].$data['type'].$data['id'].$salt);
                $str .= "auth=\"{$auth}\"";
            }
            $str .= "></meting-js>\n";
        }
        return $str;
    }

    public static function addButton()
    {
        $url = Typecho_Common::url('action/metingapi', Helper::options()->index).'?do=parse';
        $dir = Helper::options()->pluginUrl.'/Meting/assets/editer.js?v='.METING_VERSION;
        echo "<script>var murl='{$url}';</script>
              <script type=\"text/javascript\" src=\"{$dir}\"></script>";
    }

    # https://github.com/WordPress/WordPress/blob/master/wp-includes/shortcodes.php#L508
    private static function shortcode_parse_atts($text)
    {
        $atts = array();
        $pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|(\S+)(?:\s|$)/';
        $text = preg_replace("/[\x{00a0}\x{200b}]+/u", " ", $text);
        if (preg_match_all($pattern, $text, $match, PREG_SET_ORDER)) {
            foreach ($match as $m) {
                if (!empty($m[1])) {
                    $atts[strtolower($m[1])] = stripcslashes($m[2]);
                } elseif (!empty($m[3])) {
                    $atts[strtolower($m[3])] = stripcslashes($m[4]);
                } elseif (!empty($m[5])) {
                    $atts[strtolower($m[5])] = stripcslashes($m[6]);
                } elseif (isset($m[7]) && strlen($m[7])) {
                    $atts[] = stripcslashes($m[7]);
                } elseif (isset($m[8])) {
                    $atts[] = stripcslashes($m[8]);
                }
            }
            foreach ($atts as &$value) {
                if (false !== strpos($value, '<')) {
                    if (1 !== preg_match('/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value)) {
                        $value = '';
                    }
                }
            }
        } else {
            $atts = ltrim($text);
        }
        return $atts;
    }

    # https://github.com/WordPress/WordPress/blob/master/wp-includes/shortcodes.php#L254
    private static function get_shortcode_regex($tagnames = null)
    {
        $tagregexp = join('|', array_map('preg_quote', $tagnames));
        return '\[(\[?)('.$tagregexp.')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';
    }

    public static function installCheck()
    {
        if (!extension_loaded('curl')) {
            throw new Typecho_Plugin_Exception(_t('缺少 cURL 拓展'));
        }
        if (!(extension_loaded('openssl') || extension_loaded('mcrypt'))) {
            throw new Typecho_Plugin_Exception(_t('缺少 openssl/mcrypt 拓展'));
        }
    }
}
