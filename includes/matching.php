<?php
if (!defined('ABSPATH')) { exit; }
final class APLR_Matching {
    const META = '_aplr_review_rule';
    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_aplr_rule', array(__CLASS__, 'save'));
        add_filter('the_content', array(__CLASS__, 'append'), 20);
    }
    public static function menu() { add_submenu_page('options-general.php','Reviews by page','Reviews by page','manage_options','aplr-matching',array(__CLASS__,'page')); }
    public static function dictionary() {
        return array(
            'EV charger'=>array('ev charger','ev charging','electric vehicle charger','charging station','tesla charger','tesla wall connector','wall connector','nema 14-50'),
            'Panel'=>array('electrical panel','panel upgrade','panel replacement','breaker panel','circuit breaker'),
            'Lighting'=>array('lighting','light fixture','chandelier','recessed light'),
            'Outlet'=>array('outlet','receptacle','240v','240 volt','usb outlet'),
            'Ceiling fan'=>array('ceiling fan'), 'Generator'=>array('generator'),
            'Water heater'=>array('water heater','tankless','hot water heater'),
            'Drain'=>array('drain cleaning','clogged drain','blocked drain','hydro jetting','hydrojetting'),
            'Sewer'=>array('sewer','sewer line'), 'Leak'=>array('leak','pipe repair','burst pipe'),
            'Water line'=>array('water line','water main'), 'Toilet'=>array('toilet'),
            'Faucet'=>array('faucet','tap repair'), 'Disposal'=>array('garbage disposal'),
        );
    }
    private static function normalize($text) { $text = remove_accents(wp_strip_all_tags((string)$text)); $text = preg_replace('/[\p{Pd}_]+/u', ' ', $text); return function_exists('mb_strtolower') ? mb_strtolower($text,'UTF-8') : strtolower($text); }
    public static function contains($text, $phrase) {
        $phrase = trim(self::normalize($phrase)); if ($phrase === '') { return false; }
        $pattern = str_replace(' ', '\\s+', preg_quote($phrase, '~'));
        return preg_match('~(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])~u', self::normalize($text)) === 1;
    }
    public static function suggest($title) {
        $out = array();
        foreach (self::dictionary() as $name=>$words) {
            $matched = self::contains($title,$name);
            foreach ($words as $word) { if (self::contains($title,$word)) { $matched = true; } }
            if ($matched) { $out = array_merge($out,$words); }
        }
        return array_values(array_unique($out));
    }
    public static function id($review) { return !empty($review['reviewId']) && is_string($review['reviewId']) ? $review['reviewId'] : hash('sha256',wp_json_encode($review)); }
    private static function binding() { $s=AP_Local_Reviews::settings(); return hash('sha256',wp_json_encode($s['selected'] ?? array())); }
    private static function ids($input) {
        if (!is_array($input)) { return array(); }
        return array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field',array_filter($input,'is_string'))))),0,100);
    }
    public static function rule($id) {
        $post=get_post($id);
        if (!$post || !in_array($post->post_type,array('post','page'),true) || $post->post_status !== 'publish' || $post->post_password !== '') { return array(); }
        $r=get_post_meta($id,self::META,true); return is_array($r) && !empty($r['enabled']) ? $r : array();
    }
    public static function choose($reviews, $rule) {
        $result=array(); $general=array(); $binding=self::binding();
        $include=($rule['binding'] ?? '') === $binding ? ($rule['include'] ?? array()) : array();
        $exclude=($rule['binding'] ?? '') === $binding ? ($rule['exclude'] ?? array()) : array();
        $pool=get_option('aplr_general_reviews',array());
        $curated=($pool['binding'] ?? '') === $binding ? ($pool['ids'] ?? array()) : array();
        $service_words=array_merge(array('charger','heater','shower','sink','installed','installation','repaired','repair','replacement','replaced','unclogged','fixed','wiring','rewiring','surge protection','sump pump','backflow'), ...array_values(self::dictionary()));
        // Include all configured service phrases when deciding whether a fallback is truly general.
        $configured=get_option('aplr_service_phrases',array());
        if (is_array($configured)) { foreach ($configured as $phrases) { if (is_array($phrases)) { $service_words=array_merge($service_words,$phrases); } } }
        foreach ($reviews as $review) {
            if (!is_array($review)) { continue; } $id=self::id($review); if (in_array($id,$exclude,true)) { continue; }
            $text=(string)($review['comment'] ?? '');
            $matched=in_array($id,$include,true);
            foreach (($rule['keywords'] ?? array()) as $phrase) { if (self::contains($text,$phrase)) { $matched=true; break; } }
            if ($matched) { $result[]=$review; continue; }
            $is_general=in_array($id,$curated,true);
            if (!$is_general && !$curated && trim($text) !== '') {
                $is_general=false;
                foreach (array('professional','friendly','punctual','on time','responsive','communication','recommend','service','experience','courteous','helpful','great company','excellent company') as $phrase) { if (self::contains($text,$phrase)) { $is_general=true; break; } }
                foreach ($service_words as $word) { if (self::contains($text,$word)) { $is_general=false; break; } }
            }
            if ($is_general) { $general[]=$review; }
        }
        if ($result) { return array('reviews'=>$result,'mode'=>'related'); }
        return array('reviews'=>!empty($rule['fallback']) ? array_slice($general,0,1) : array(),'mode'=>'general');
    }
    public static function append($content) {
        if (is_admin() || !is_singular(array('page','post')) || !in_the_loop() || !is_main_query() || is_feed()) { return $content; }
        $id=get_queried_object_id(); $r=self::rule($id);
        $post=get_post($id);
        if (!$r || empty($r['append']) || has_shortcode($post->post_content ?? '', 'ap_google_business') || has_shortcode($content,'ap_google_business') || strpos($content,'aplr-mount') !== false) { return $content; }
        return $content.AP_Local_Reviews::shortcode(array('page_id'=>$id,'related'=>'auto'));
    }
    private static function guard() { if (!current_user_can('manage_options')) { wp_die('Administrator access required.','',array('response'=>403)); } }
    public static function save() {
        self::guard(); check_admin_referer('aplr_rule');
        $id=isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ($id) {
            $post=get_post($id);
            if (!$post || !in_array($post->post_type,array('post','page'),true) || !current_user_can('edit_post',$id)) { wp_die('Invalid page.'); }
            $raw=isset($_POST['keywords']) && is_string($_POST['keywords']) ? wp_unslash($_POST['keywords']) : '';
            $keywords=array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field',(preg_split('/[\r\n,]+/u',function_exists('mb_substr') ? mb_substr($raw,0,8000,'UTF-8') : $raw) ?: array()))))),0,40);
            if (!$keywords) { $keywords=self::suggest($post->post_title); }
            $r=array('enabled'=>!empty($_POST['enabled']),'append'=>!empty($_POST['append']),'fallback'=>!empty($_POST['fallback']),'keywords'=>$keywords,'binding'=>self::binding(),'include'=>self::ids($_POST['include'] ?? array()),'exclude'=>self::ids($_POST['exclude'] ?? array()));
            update_post_meta($id,self::META,$r);
            $all=get_option('aplr_service_phrases',array()); if (!is_array($all)) { $all=array(); } $all[$id]=$r['enabled'] ? $keywords : array(); update_option('aplr_service_phrases',$all,false);
        } else { update_option('aplr_general_reviews',array('binding'=>self::binding(),'ids'=>self::ids($_POST['general'] ?? array())),false); }
        wp_safe_redirect(add_query_arg(array('page'=>'aplr-matching','edit'=>$id,'saved'=>1),admin_url('options-general.php'))); exit;
    }
    private static function start($id) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aplr_rule"><input type="hidden" name="post_id" value="'.(int)$id.'">'; wp_nonce_field('aplr_rule');
    }
    private static function inventory() { $data=AP_Local_Reviews::cache(); return $data['reviews_response']['reviews'] ?? array(); }
    public static function page() {
        self::guard(); $id=isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        echo '<div class="wrap"><h1>Reviews by page</h1><p>Match original Google review text to services using whole phrases. No star-rating filter; the business-wide rating stays unchanged. Topic matching is approximate: review the matches before enabling automatic placement. Saved rules are re-evaluated after each Google sync; newly fetched matching reviews can appear automatically.</p>';
        if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>Rules saved.</p></div>'; }
        $cache=AP_Local_Reviews::cache();
        if (!$cache) { echo '<div class="notice notice-warning"><p>Connect, select a business and Sync now before reviewing matches. You can configure page rules now.</p></div>'; }
        elseif (!empty($cache['truncated'])) { echo '<div class="notice notice-warning"><p>Matching uses the most recent 500 reviews; more reviews exist at Google.</p></div>'; }
        echo '<p><a href="'.esc_url(admin_url('options-general.php?page=aplr-matching')).'">All pages and posts</a> | <a href="'.esc_url(admin_url('options-general.php?page=aplr-matching&general=1')).'">General review fallback pool</a></p>';
        if (isset($_GET['general'])) {
            $pool=get_option('aplr_general_reviews',array());$ids=($pool['binding'] ?? '')===self::binding()?($pool['ids'] ?? array()):array();self::start(0);
            echo '<h2>General reviews</h2><p>Optionally mark reviews that genuinely describe overall service. Otherwise fallback uses a written review about general service or communication without recognized job/service phrases. No text is rewritten. If no suitable general review exists, the widget stays hidden.</p>';
            foreach (self::inventory() as $review) { self::review_control($review,'general',$ids); } submit_button('Save general review pool');echo '</form></div>';return;
        }
        if ($id) {
            $post=get_post($id);if (!$post || !in_array($post->post_type,array('page','post'),true) || !current_user_can('edit_post',$id)) { echo '<p>Page not found.</p></div>'; return; }
            $r=get_post_meta($id,self::META,true);if (!is_array($r)) { $r=array(); }
            $suggest=self::suggest($post->post_title);$keywords=$r['keywords'] ?? $suggest;
            echo '<h2>'.esc_html($post->post_title).'</h2>';self::start($id);
            foreach (array('enabled'=>'Enable page-specific matching','fallback'=>'Show one general review when no related reviews match','append'=>'Automatically add reviews after this page’s main content') as $key=>$label) {
                $checked=isset($r[$key]) ? $r[$key] : ($key==='fallback'); echo '<p><label><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked($checked,true,false).'> '.esc_html($label).'</label></p>';
            }
            echo '<p><label for="aplr-keywords">Service keywords / synonyms (one phrase per line)</label></p><textarea id="aplr-keywords" name="keywords" rows="8" cols="65" maxlength="8000">'.esc_textarea(implode("\n",$keywords)).'</textarea><p>Title-based suggestions are prefilled for common electrical/plumbing services. Add your own phrases for any industry. Avoid broad words such as “good” or “service”. Matching uses the customer’s text, not the business reply.</p><p>Manual block: <code>[ap_google_business related="auto" layout="carousel" autoplay="yes"]</code>. Add <code>related="no"</code> for business-wide reviews.</p>';
            $bound=($r['binding'] ?? '')===self::binding();$include=$bound?($r['include'] ?? array()):array();$exclude=$bound?($r['exclude'] ?? array()):array();
            echo '<h3>Review matches / manual correction</h3><p>Explicit exclude wins over a keyword or include. Review text and rating are never modified. Only select reviews truly related to this service.</p>';
            foreach (self::inventory() as $review) {
                $rid=self::id($review);$auto=false;foreach($keywords as $phrase){if(self::contains($review['comment'] ?? '',$phrase)){$auto=true;break;}}
                echo '<fieldset style="border:1px solid #ccd0d4;padding:12px;margin:10px 0"><legend>'.($auto?'Keyword match':'No keyword match').'</legend><p>'.esc_html(($review['reviewer']['displayName'] ?? 'Google reviewer').' · '.($review['starRating'] ?? '')).'</p><p>'.nl2br(esc_html($review['comment'] ?? 'Rating only')).'</p>';
                foreach(array('include'=>$include,'exclude'=>$exclude)as$key=>$ids){echo '<label style="margin-right:20px"><input type="checkbox" name="'.$key.'[]" value="'.esc_attr($rid).'" '.checked(in_array($rid,$ids,true),true,false).'> '.esc_html(ucfirst($key)).'</label>';}echo '</fieldset>';
            }
            submit_button('Save page rule');echo '</form>';
            if (!empty($r['enabled'])) { $selected=self::choose(self::inventory(),$r);echo '<h3>Saved rule result</h3><p>'.count($selected['reviews']).' reviews available; mode: '.esc_html($selected['mode']).'.</p>'; }
            echo '</div>';return;
        }
        $search=isset($_GET['s']) && is_string($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';$paged=max(1,isset($_GET['paged'])?absint($_GET['paged']):1);
        echo '<form method="get"><input type="hidden" name="page" value="aplr-matching"><input name="s" value="'.esc_attr($search).'" placeholder="Search pages and posts">';submit_button('Search','secondary','',false);echo '</form>';
        $query=new WP_Query(array('post_type'=>array('page','post'),'post_status'=>array('publish','draft','pending','future','private'),'posts_per_page'=>30,'paged'=>$paged,'s'=>$search,'orderby'=>'title','order'=>'ASC'));
        echo '<table class="widefat striped"><thead><tr><th>Page / post</th><th>Status</th><th>Matching</th><th>Action</th></tr></thead><tbody>';
        foreach($query->posts as $post){$r=get_post_meta($post->ID,self::META,true);echo '<tr><td>'.esc_html($post->post_title ?: '(Untitled)').' <small>('.esc_html($post->post_type).')</small></td><td>'.esc_html($post->post_status).'</td><td>'.(!empty($r['enabled'])?'Enabled':'Off').'</td><td><a href="'.esc_url(add_query_arg(array('page'=>'aplr-matching','edit'=>$post->ID),admin_url('options-general.php'))).'">Configure / review matches</a></td></tr>';}
        echo '</tbody></table>';echo wp_kses_post(paginate_links(array('base'=>add_query_arg('paged','%#%'),'current'=>$paged,'total'=>$query->max_num_pages)));echo '<p>Private, password-protected and unpublished pages can be configured, but are never resolved by the public review endpoint until public. No automatic placement is enabled until you save a page rule.</p></div>';
    }
    private static function review_control($review,$name,$ids) { echo '<p><label><input type="checkbox" name="'.esc_attr($name).'[]" value="'.esc_attr(self::id($review)).'" '.checked(in_array(self::id($review),$ids,true),true,false).'> '.esc_html(($review['reviewer']['displayName'] ?? 'Google reviewer').' — '.($review['comment'] ?? 'Rating only')).'</label></p>'; }
}
APLR_Matching::boot();
