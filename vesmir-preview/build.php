<?php
// Рендерит тему в статичный index.html (заглушки WP-функций). Запуск: php build.php
define('ABSPATH', 1);
$T = dirname(__DIR__) . '/vesmir-theme';
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);} function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);} function wp_kses_post($s){return $s;}
function get_option($k){return 0;} function get_field($n,$id=null){return null;} function get_queried_object_id(){return 0;}
function is_front_page(){return true;} function home_url($p=''){return './';} function admin_url($p=''){return '#';}
function bloginfo($k){echo $k==='name'?'Vesmir':($k==='charset'?'UTF-8':'');}
function language_attributes(){echo 'lang="ru-RU"';} function body_class(){echo 'class="home"';}
function wp_head(){echo '<title>Производство детского трикотажа — Vesmir</title><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap"><link rel="stylesheet" href="assets/css/main.css">';}
function wp_footer(){echo '<script src="assets/js/main.js" defer></script>';} function wp_body_open(){}
function has_custom_logo(){return false;} function wp_nonce_field(){}
function vz_menu_fallback_static(){}
function wp_nav_menu($a){vz_menu_fallback();}
function get_template_part($s,$n=null){global $T; include "$T/$s-$n.php";}
function get_header(){global $T; include "$T/header.php";} function get_footer(){global $T; include "$T/footer.php";}
function wp_trim_words($s){return $s;} function wp_get_attachment_image_url(){return '';}
function is_home(){return false;}
class WP_Query{function have_posts(){return false;}}
require "$T/inc/defaults.php"; require "$T/inc/helpers.php";
function vz_form_notice(){}
function vz_menu($l,$c='nav__list'){vz_menu_fallback();}
function vz_menu_fallback(){echo '<ul class="nav__list">';foreach(['#gallery'=>'Галерея работ','#about'=>'О нас','#segment'=>'Для кого?','#blog'=>'Наш блог'] as $h=>$l)echo '<li><a href="'.$h.'">'.$l.'</a></li>';echo '</ul>';}
ob_start(); include "$T/front-page.php"; $html = ob_get_clean();
$html = str_replace('<section class="section section--tint" id="blog">','<section class="section section--tint" id="blog">',$html);
file_put_contents(__DIR__.'/index.html',$html);
echo strlen($html)," bytes\n";
