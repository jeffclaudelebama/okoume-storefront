<?php
/**
 * Plugin Name: OKOUMÉ Headless Commerce
 * Description: Product fields and secured API for the OKOUMÉ PWA.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class Okoume_Headless_Commerce {
  /* __Host- prevents a sibling subdomain from setting a competing cookie. */
  const COOKIE = '__Host-okoume_session';
  const LEGACY_COOKIE = 'okoume_session';
  const SESSION_TTL = 1209600; // 14 days.
  const SESSION_ROTATION_SECONDS = 604800; // 7 days.
  const META = ['condition','storage','color','battery','warranty','accessories','technical_controls','defects'];
  private $session_context = null;
  private $session_checked = false;
  public function __construct() {
    add_action('init', [$this, 'register_categories']);
    add_action('woocommerce_product_options_general_product_data', [$this, 'product_fields']);
    add_action('woocommerce_process_product_meta', [$this, 'save_product_fields']);
    add_action('rest_api_init', [$this, 'routes']);
    add_filter('rest_pre_serve_request', [$this, 'cors_headers'], 100, 4);
    add_filter('rest_endpoints', [$this, 'restrict_public_user_endpoints']);
    add_filter('user_has_cap', [$this, 'grant_staff_capability'], 10, 4);
  }
  private function staff_capabilities() {
    $cap='okoume_manage_customer_messages';
    return [
      'edit_post'=>$cap,'read_post'=>$cap,'delete_post'=>$cap,
      'edit_posts'=>$cap,'edit_others_posts'=>$cap,'publish_posts'=>$cap,
      'read_private_posts'=>$cap,'delete_posts'=>$cap,'delete_private_posts'=>$cap,
      'delete_published_posts'=>$cap,'delete_others_posts'=>$cap,
      'edit_private_posts'=>$cap,'edit_published_posts'=>$cap,'create_posts'=>$cap,
    ];
  }
  public function grant_staff_capability($allcaps,$caps,$args,$user) {
    if (in_array('okoume_manage_customer_messages',$caps,true) && (!empty($allcaps['manage_woocommerce']) || !empty($allcaps['manage_options']))) $allcaps['okoume_manage_customer_messages']=true;
    return $allcaps;
  }
  public function register_categories() {
    foreach (['smartphones'=>'Smartphones','ordinateurs'=>'Ordinateurs','tablettes'=>'Tablettes','gaming'=>'Gaming','accessoires'=>'Accessoires'] as $slug=>$name) {
      if (!term_exists($slug, 'product_cat')) wp_insert_term($name, 'product_cat', ['slug'=>$slug]);
    }
    register_post_type('okoume_restock_request', [
      'labels'=>['name'=>'Alertes de disponibilité','singular_name'=>'Alerte de disponibilité'],
      'public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'show_in_rest'=>false,
      'show_ui'=>true,'show_in_menu'=>'woocommerce','supports'=>['title'],
      'capability_type'=>['okoume_restock_request','okoume_restock_requests'],'capabilities'=>$this->staff_capabilities(),'map_meta_cap'=>true,
    ]);
    register_post_type('okoume_contact_message', [
      'labels'=>['name'=>'Messages clients','singular_name'=>'Message client'],
      'public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'show_in_rest'=>false,
      'show_ui'=>true,'show_in_menu'=>'woocommerce','supports'=>['title','editor'],
      'capability_type'=>['okoume_contact_message','okoume_contact_messages'],'capabilities'=>$this->staff_capabilities(),'map_meta_cap'=>true,
    ]);
  }
  public function product_fields() {
    echo '<div class="options_group">';
    woocommerce_wp_checkbox(['id'=>'_okoume_enabled','label'=>'Produit OKOUMÉ','description'=>'Afficher dans le catalogue PWA OKOUMÉ.']);
    woocommerce_wp_select(['id'=>'_okoume_condition','label'=>'État','options'=>[''=>'— Sélectionner —','neuf'=>'Neuf','comme-neuf'=>'Comme neuf','tres-bon-etat'=>'Très bon état','bon-etat'=>'Bon état','reconditionne'=>'Reconditionné']]);
    $labels=['storage'=>'Stockage','color'=>'Couleur','battery'=>'Batterie','warranty'=>'Garantie','accessories'=>'Accessoires inclus','technical_controls'=>'Contrôles techniques','defects'=>'Défauts éventuels'];
    foreach ($labels as $key=>$label) woocommerce_wp_text_input(['id'=>'_okoume_'.$key,'label'=>$label]);
    echo '</div>';
  }
  public function save_product_fields($id) {
    update_post_meta($id, '_okoume_enabled', isset($_POST['_okoume_enabled']) ? 'yes' : 'no');
    foreach (array_merge(['condition'], self::META) as $key) if (isset($_POST['_okoume_'.$key])) update_post_meta($id, '_okoume_'.$key, sanitize_text_field(wp_unslash($_POST['_okoume_'.$key])));
  }
  private function product($product) {
    $images=[]; foreach (array_slice(array_filter(array_unique([$product->get_image_id(), ...$product->get_gallery_image_ids()])),0,10) as $id) $images[]=['id'=>$id,'src'=>wp_get_attachment_image_url($id,'large'),'alt'=>get_post_meta($id,'_wp_attachment_image_alt',true)];
    $meta=[]; foreach (self::META as $key) $meta[$key]=get_post_meta($product->get_id(), '_okoume_'.$key, true);
    $condition=get_post_meta($product->get_id(), '_okoume_condition', true);
    return ['id'=>$product->get_id(),'name'=>$product->get_name(),'slug'=>$product->get_slug(),'price'=>(float)$product->get_price(),'regular_price'=>(float)$product->get_regular_price(),'sale_price'=>(float)$product->get_sale_price(),'description'=>wp_kses_post($product->get_description()),'short_description'=>wp_kses_post($product->get_short_description()),'stock_status'=>$product->get_stock_status(),'stock_quantity'=>$product->get_manage_stock()?$product->get_stock_quantity():null,'categories'=>array_map(fn($c)=>['id'=>$c->term_id,'slug'=>$c->slug,'name'=>$c->name], get_the_terms($product->get_id(),'product_cat') ?: []),'images'=>$images,'condition'=>$condition,'meta'=>$meta];
  }
  public function routes() {
    register_rest_route('okoume/v1','/products',[['methods'=>'GET','callback'=>[$this,'products'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/products/(?P<id>\d+)',[['methods'=>'GET','callback'=>[$this,'one_product'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/auth/(?P<action>login|register|logout)',[['methods'=>'POST','callback'=>[$this,'auth'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/account',[['methods'=>'GET','callback'=>[$this,'account'],'permission_callback'=>'__return_true'],['methods'=>'POST','callback'=>[$this,'update_account'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/orders',[['methods'=>'GET','callback'=>[$this,'orders'],'permission_callback'=>'__return_true'],['methods'=>'POST','callback'=>[$this,'checkout'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/orders/track',[['methods'=>'GET','callback'=>[$this,'track_order'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/stripe/return',[['methods'=>'GET','callback'=>[$this,'stripe_return'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/stripe/webhook',[['methods'=>'POST','callback'=>[$this,'stripe_webhook'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/restock',[['methods'=>'POST','callback'=>[$this,'restock'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/contact',[['methods'=>'POST','callback'=>[$this,'contact'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/chat',[['methods'=>'POST','callback'=>[$this,'chat'],'permission_callback'=>'__return_true']]);
  }
  private function normalise_origin($value) {
    $parts=wp_parse_url(trim((string)$value));
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
    $scheme=strtolower($parts['scheme']);
    if (!in_array($scheme,['https','http'],true) || isset($parts['user']) || isset($parts['pass'])) return '';
    return $scheme.'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.(int)$parts['port'] : '');
  }
  private function allowed_origins() {
    $origins=['https://okoume.find-gabon.com',$this->normalise_origin(home_url('/')),$this->normalise_origin(site_url('/'))];
    $origins=apply_filters('okoume_allowed_origins',$origins);
    if (!is_array($origins)) return [];
    return array_values(array_unique(array_filter(array_map([$this,'normalise_origin'],$origins))));
  }
  private function request_origin() { return $this->normalise_origin($_SERVER['HTTP_ORIGIN'] ?? ''); }
  private function origin_is_allowed($origin) { return $origin && in_array($origin,$this->allowed_origins(),true); }
  public function cors_headers($served,$result,$request,$server) {
    if (!($request instanceof WP_REST_Request) || strpos($request->get_route(),'/okoume/v1/')!==0) return $served;
    header_remove('Access-Control-Allow-Origin'); header_remove('Access-Control-Allow-Credentials'); header_remove('Access-Control-Allow-Headers'); header_remove('Access-Control-Allow-Methods'); header_remove('Access-Control-Expose-Headers');
    $origin=$this->request_origin();
    if ($this->origin_is_allowed($origin)) {
      header('Access-Control-Allow-Origin: '.$origin,true);
      header('Access-Control-Allow-Credentials: true',true);
      header('Access-Control-Allow-Methods: GET, POST, OPTIONS',true);
      header('Access-Control-Allow-Headers: Content-Type, X-Okoume-CSRF',true);
      header('Access-Control-Expose-Headers: X-OKOUME-CSRF',true);
      header('Access-Control-Max-Age: 600',true);
      header('Vary: Origin',false);
    }
    $route=$request->get_route();
    if ($route==='/okoume/v1/account' || $route==='/okoume/v1/orders' || strpos($route,'/okoume/v1/auth/')===0) {
      header('Cache-Control: private, no-store, max-age=0',true);
      header('Pragma: no-cache',true);
    }
    return $served;
  }
  public function restrict_public_user_endpoints($endpoints) {
    if (current_user_can('list_users')) return $endpoints;
    unset($endpoints['/wp/v2/users']);
    foreach (array_keys($endpoints) as $route) if (strpos($route,'/wp/v2/users/(?P<id>')===0) unset($endpoints[$route]);
    return $endpoints;
  }
  private function require_trusted_origin() {
    if ($this->origin_is_allowed($this->request_origin())) return true;
    return new WP_Error('invalid_origin','Cette action doit être envoyée depuis le site OKOUMÉ.',['status'=>403]);
  }
  public function products($request) {
    $args=['status'=>'publish','limit'=>50,'meta_key'=>'_okoume_enabled','meta_value'=>'yes'];
    if ($request['category']) $args['category']=sanitize_title($request['category']);
    return rest_ensure_response(array_map([$this,'product'], wc_get_products($args)));
  }
  public function one_product($request) { $p=wc_get_product((int)$request['id']); return $p && $p->get_meta('_okoume_enabled')==='yes' ? rest_ensure_response($this->product($p)) : new WP_Error('not_found','Produit introuvable',['status'=>404]); }
  public function restock($request) {
    $product=wc_get_product((int)$request->get_param('product_id'));
    $phone=preg_replace('/\D+/', '', (string)$request->get_param('phone'));
    if (!$product || $product->get_meta('_okoume_enabled')!=='yes') return new WP_Error('not_found','Produit introuvable.',['status'=>404]);
    if ($product->is_in_stock()) return new WP_Error('available','Ce produit est actuellement disponible.',['status'=>409]);
    if (strlen($phone)<8 || strlen($phone)>15) return new WP_Error('invalid_phone','Saisissez un numéro WhatsApp valide.',['status'=>400]);
    if (!$this->intake_allowed('restock')) return new WP_Error('rate_limited','Veuillez patienter avant de demander une autre alerte.',['status'=>429]);
    $id=wp_insert_post(['post_type'=>'okoume_restock_request','post_status'=>'private','post_title'=>'Alerte — '.$product->get_name().' — '.$phone]);
    if (is_wp_error($id)) return new WP_Error('save_failed','Impossible d’enregistrer votre demande.',['status'=>500]);
    update_post_meta($id,'_okoume_product_id',$product->get_id());
    update_post_meta($id,'_okoume_whatsapp',$phone);
    return ['ok'=>true,'message'=>'Votre demande a bien été enregistrée. Nous vous écrirons sur WhatsApp dès le retour en stock.'];
  }
  private function client_ip() {
    $ip=isset($_SERVER['REMOTE_ADDR']) ? trim((string)wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    $ip=apply_filters('okoume_client_ip',$ip);
    return filter_var($ip,FILTER_VALIDATE_IP) ? $ip : 'unknown';
  }
  private function rate_key($scope,$identifier='') {
    return 'okoume_rate_'.hash_hmac('sha256',$scope.'|'.$this->client_ip().'|'.$identifier,wp_salt('nonce'));
  }
  private function intake_allowed($scope,$limit=3,$window=1800,$identifier='') {
    $key=$this->rate_key($scope,$identifier);
    if ((int)get_transient($key)>=$limit) return false;
    set_transient($key,1+(int)get_transient($key),(int)$window);
    return true;
  }
  private function save_message($type,$name,$email,$message) {
    $id=wp_insert_post(['post_type'=>'okoume_contact_message','post_status'=>'private','post_title'=>sprintf('%s — %s',ucfirst($type),$name ?: 'Client'),'post_content'=>$message]);
    if (!is_wp_error($id)) { update_post_meta($id,'_okoume_message_type',$type); update_post_meta($id,'_okoume_email',$email); }
    return !is_wp_error($id);
  }
  public function contact($request) {
    $name=sanitize_text_field($request->get_param('name')); $email=sanitize_email($request->get_param('email')); $subject=sanitize_text_field($request->get_param('subject')); $message=sanitize_textarea_field($request->get_param('message'));
    if (!$name || !$email || !$subject || mb_strlen($message)<10) return new WP_Error('invalid_contact','Indiquez votre nom, un e-mail valide, un objet et un message d’au moins 10 caractères.',['status'=>400]);
    if (!$this->intake_allowed('contact')) return new WP_Error('rate_limited','Veuillez patienter avant d’envoyer un autre message.',['status'=>429]);
    $saved=$this->save_message('contact',$name,$email,"Objet : {$subject}\n\n{$message}");
    $sent=wp_mail('info@find-gabon.com','[OKOUMÉ] '.($subject ?: 'Nouveau message'),"Nom : {$name}\nE-mail : {$email}\n\n{$message}",['Content-Type: text/plain; charset=UTF-8','Reply-To: '.$email]);
    if (!$saved && !$sent) return new WP_Error('contact_unavailable','Votre message n’a pas pu être enregistré. Réessayez plus tard.',['status'=>503]);
    return ['ok'=>true,'message'=>'Votre message a bien été transmis à l’équipe OKOUMÉ.'];
  }
  private function phone($value) { return preg_replace('/\D+/', '', (string)$value); }
  private function user_by_phone($phone) { $users=get_users(['number'=>1,'meta_key'=>'_okoume_phone','meta_value'=>$phone,'fields'=>'all']); return $users ? $users[0] : false; }
  private function cookie_options($expires) {
    return ['expires'=>(int)$expires,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict'];
  }
  private function clear_legacy_cookie() { setcookie(self::LEGACY_COOKIE,'',$this->cookie_options(time()-HOUR_IN_SECONDS)); }
  private function clear_session_cookie() {
    setcookie(self::COOKIE,'',$this->cookie_options(time()-HOUR_IN_SECONDS));
    /* Remove the former stateless cookie after the upgrade as well. */
    $this->clear_legacy_cookie();
  }
  private function set_session_cookie($user_id,$issued,$token) {
    setcookie(self::COOKIE,'v1.'.(int)$user_id.'.'.(int)$issued.'.'.$token,$this->cookie_options($issued+self::SESSION_TTL));
    setcookie(self::LEGACY_COOKIE,'',$this->cookie_options(time()-HOUR_IN_SECONDS));
  }
  private function current_session($rotate=true) {
    if (!$this->session_checked) {
      $this->session_checked=true;
      if (isset($_COOKIE[self::LEGACY_COOKIE])) $this->clear_legacy_cookie();
      $cookie=$_COOKIE[self::COOKIE] ?? '';
      $raw=is_string($cookie) ? (string)wp_unslash($cookie) : '';
      if (preg_match('/^v1\.([1-9][0-9]*)\.([0-9]{10})\.([A-Za-z0-9]{20,128})$/',$raw,$matches)) {
        $user=get_user_by('id',(int)$matches[1]);
        $issued=(int)$matches[2]; $token=$matches[3];
        if ($user && $issued<=time()+300 && $issued+self::SESSION_TTL>=time() && class_exists('WP_Session_Tokens')) {
          $manager=WP_Session_Tokens::get_instance($user->ID);
          if ($manager->verify($token)) $this->session_context=['user'=>$user,'token'=>$token,'issued'=>$issued,'manager'=>$manager];
        }
      }
      if (!$this->session_context && $raw) $this->clear_session_cookie();
    }
    if ($rotate && $this->session_context && time()-$this->session_context['issued']>=self::SESSION_ROTATION_SECONDS) {
      $this->issue_session($this->session_context['user'],true);
    }
    return $this->session_context;
  }
  private function issue_session($user,$replace_current=true) {
    if (!($user instanceof WP_User) || !class_exists('WP_Session_Tokens')) return false;
    $current=$this->current_session(false);
    if ($replace_current && $current) $current['manager']->destroy($current['token']);
    $issued=time(); $manager=WP_Session_Tokens::get_instance($user->ID); $token=$manager->create($issued+self::SESSION_TTL);
    $this->set_session_cookie($user->ID,$issued,$token);
    return $this->session_context=['user'=>$user,'token'=>$token,'issued'=>$issued,'manager'=>$manager];
  }
  private function destroy_current_session() {
    $current=$this->current_session(false);
    if ($current) $current['manager']->destroy($current['token']);
    $this->session_context=null; $this->session_checked=true; $this->clear_session_cookie();
  }
  private function csrf_token($context) {
    return $context ? hash_hmac('sha256','okoume-csrf-v1|'.$context['token'],wp_salt('nonce')) : '';
  }
  private function require_authenticated_mutation() {
    $context=$this->current_session(false);
    if (!$context) return new WP_Error('unauthorized','Connexion requise.',['status'=>401]);
    if (!$this->origin_is_allowed($this->request_origin())) return new WP_Error('invalid_origin','Cette action doit être envoyée depuis le site OKOUMÉ.',['status'=>403]);
    $provided=isset($_SERVER['HTTP_X_OKOUME_CSRF']) ? trim((string)wp_unslash($_SERVER['HTTP_X_OKOUME_CSRF'])) : '';
    if (!$provided || !hash_equals($this->csrf_token($context),$provided)) return new WP_Error('invalid_csrf','Votre session doit être actualisée avant de continuer.',['status'=>403]);
    return $context;
  }
  private function user() { $context=$this->current_session(); return $context ? $context['user'] : false; }
  private function session($user,$refresh_cookie=true) {
    $context=$refresh_cookie ? $this->issue_session($user,true) : $this->current_session(false);
    if (!$context || $context['user']->ID!==$user->ID) $context=$this->issue_session($user,false);
    $csrf=$this->csrf_token($context); if ($csrf) header('X-OKOUME-CSRF: '.$csrf,true);
    return ['id'=>$user->ID,'email'=>$user->user_email,'first_name'=>$user->first_name,'last_name'=>$user->last_name,'phone'=>get_user_meta($user->ID,'_okoume_phone',true),'address'=>get_user_meta($user->ID,'_okoume_address',true),'phone_verified'=>(bool)get_user_meta($user->ID,'_okoume_phone_verified',true)];
  }
  private function attempts_key($identifier) { return $this->rate_key('auth_attempt',$identifier); }
  private function locked($identifier) { return (int)get_transient($this->attempts_key($identifier)) >= 5; }
  private function failed($identifier) { $key=$this->attempts_key($identifier); set_transient($key,(int)get_transient($key)+1,15*MINUTE_IN_SECONDS); }
  public function auth($request) {
    $action=$request['action'];
    if ($action==='logout') {
      if ($this->current_session(false)) { $guard=$this->require_authenticated_mutation(); if (is_wp_error($guard)) return $guard; }
      $this->destroy_current_session(); return ['ok'=>true];
    }
    $origin_guard=$this->require_trusted_origin(); if (is_wp_error($origin_guard)) return $origin_guard;
    $phone=$this->phone($request->get_param('phone')); $email=sanitize_email($request->get_param('email')); $password=(string)$request->get_param('password'); $attempt_key=$phone ?: $email;
    $first_name=sanitize_text_field($request->get_param('first_name')); $last_name=sanitize_text_field($request->get_param('last_name')); $address=sanitize_text_field($request->get_param('address')); $password_confirmation=(string)$request->get_param('password_confirmation');
    if (strlen($phone)<8 || strlen($phone)>15 || ($action==='register' && (!$email || !$first_name || !$last_name || !$address || strlen($password)<12 || $password!==$password_confirmation)) || ($action==='login' && !$password)) return new WP_Error('invalid_input',$action==='register'?'Renseignez tous les champs, avec un téléphone valide et deux mots de passe identiques de 12 caractères minimum.':'Téléphone et mot de passe requis.',['status'=>400]);
    if (!$this->intake_allowed('auth',30,15*MINUTE_IN_SECONDS)) return new WP_Error('rate_limited','Trop de demandes. Réessayez dans quelques minutes.',['status'=>429]);
    if ($action==='register' && !$this->intake_allowed('registration',5,HOUR_IN_SECONDS)) return new WP_Error('rate_limited','Trop de créations de compte depuis cette connexion. Réessayez plus tard.',['status'=>429]);
    if ($this->locked($attempt_key)) return new WP_Error('rate_limited','Trop de tentatives. Réessayez dans 15 minutes.',['status'=>429]);
    if ($action==='register') {
      if (email_exists($email) || $this->user_by_phone($phone)) { $this->failed($attempt_key); return new WP_Error('registration_unavailable','Impossible de créer ce compte avec ces informations.',['status'=>409]); }
      $login='okoume_'.substr($phone,-12); $id=wp_create_user($login,$password,$email); if (is_wp_error($id)) return new WP_Error('registration_unavailable','Impossible de créer ce compte avec ces informations.',['status'=>400]);
      wp_update_user(['ID'=>$id,'first_name'=>$first_name,'last_name'=>$last_name,'role'=>'customer']); update_user_meta($id,'_okoume_phone',$phone); update_user_meta($id,'_okoume_address',$address); update_user_meta($id,'billing_first_name',$first_name); update_user_meta($id,'billing_last_name',$last_name); update_user_meta($id,'billing_email',$email); update_user_meta($id,'billing_phone',$phone); update_user_meta($id,'billing_address_1',$address); update_user_meta($id,'_okoume_phone_verified',false); $user=get_user_by('id',$id);
    } else {
      $user=$this->user_by_phone($phone); if (!$user || !wp_check_password($password,$user->user_pass,$user->ID)) { $this->failed($attempt_key); return new WP_Error('invalid_login','Identifiants incorrects.',['status'=>401]); }
    }
    delete_transient($this->attempts_key($attempt_key)); return $this->session($user);
  }
  public function account() { $u=$this->user(); if (!$u) return new WP_Error('unauthorized','Connexion requise.',['status'=>401]); return $this->session($u,false); }
  public function update_account($request) {
    $context=$this->current_session(false); if (!$context) return new WP_Error('unauthorized','Connexion requise.',['status'=>401]);
    $guard=$this->require_authenticated_mutation(); if (is_wp_error($guard)) return $guard;
    $u=$context['user']; if (!$this->intake_allowed('account_update',10,HOUR_IN_SECONDS,(string)$u->ID)) return new WP_Error('rate_limited','Trop de modifications. Réessayez plus tard.',['status'=>429]);
    $first=sanitize_text_field($request->get_param('first_name')); $last=sanitize_text_field($request->get_param('last_name')); $address=sanitize_text_field($request->get_param('address')); $phone=$this->phone($request->get_param('phone'));
    if (!$first || !$last || !$address || strlen($phone)<8 || strlen($phone)>15) return new WP_Error('invalid_account','Renseignez des coordonnées valides.',['status'=>400]);
    $existing=$this->user_by_phone($phone); if ($existing && $existing->ID!==$u->ID) return new WP_Error('account_unavailable','Impossible de mettre à jour ces informations.',['status'=>409]);
    wp_update_user(['ID'=>$u->ID,'first_name'=>$first,'last_name'=>$last]); update_user_meta($u->ID,'_okoume_phone',$phone); update_user_meta($u->ID,'_okoume_address',$address); update_user_meta($u->ID,'billing_first_name',$first); update_user_meta($u->ID,'billing_last_name',$last); update_user_meta($u->ID,'billing_phone',$phone); update_user_meta($u->ID,'billing_address_1',$address);
    return $this->session(get_user_by('id',$u->ID),true);
  }
  public function orders() { $u=$this->user(); if (!$u) return new WP_Error('unauthorized','Connexion requise.',['status'=>401]); $orders=wc_get_orders(['customer_id'=>$u->ID,'limit'=>30,'orderby'=>'date','order'=>'DESC']); return array_map(fn($o)=>['id'=>$o->get_id(),'number'=>$o->get_order_number(),'status'=>$o->get_status(),'total'=>$o->get_total(),'date'=>$o->get_date_created()->date('c'),'items'=>array_map(fn($i)=>['name'=>$i->get_name(),'quantity'=>$i->get_quantity()],$o->get_items())],$orders); }
  private function tracking_code($order) {
    $code=$order->get_meta('_okoume_tracking_code');
    if (!$code) { $code='OZ-'.str_pad((string)$order->get_id(),6,'0',STR_PAD_LEFT).'-GA-'.wp_rand(100000,999999); $order->update_meta_data('_okoume_tracking_code',$code); $order->save(); }
    return $code;
  }
  public function track_order($request) {
    $reference=strtoupper(sanitize_text_field($request->get_param('number')));
    $phone=preg_replace('/\D+/', '', sanitize_text_field($request->get_param('phone')));
    if (!$reference) return new WP_Error('invalid_tracking','Référence de commande requise.',['status'=>400]);
    $matches=wc_get_orders(['limit'=>1,'meta_key'=>'_okoume_tracking_code','meta_value'=>$reference]);
    $order=$matches ? $matches[0] : null;
    if (!$order && ctype_digit($reference)) $order=wc_get_order((int)$reference);
    if (!$order) return new WP_Error('not_found','Aucune commande ne correspond à cette référence.',['status'=>404]);
    if (ctype_digit($reference) && (strlen($phone)<6 || !hash_equals(substr(preg_replace('/\D+/', '', $order->get_billing_phone()), -8), substr($phone, -8)))) return new WP_Error('not_found','Pour une ancienne référence, saisissez également le téléphone de commande.',['status'=>404]);
    $labels=['pending'=>'En attente de paiement','on-hold'=>'Commande reçue — en attente de confirmation','processing'=>'En préparation','completed'=>'Terminée','cancelled'=>'Annulée','failed'=>'Échec de la commande','refunded'=>'Remboursée'];
    return ['number'=>$this->tracking_code($order),'status'=>$order->get_status(),'status_label'=>$labels[$order->get_status()] ?? 'Commande reçue','updated'=>$order->get_date_modified()->date('c'),'items'=>array_map(fn($i)=>['name'=>wp_strip_all_tags($i->get_name()),'quantity'=>$i->get_quantity()],$order->get_items())];
  }
  public function chat($request) {
    $message=sanitize_textarea_field($request->get_param('message')); $email=sanitize_email($request->get_param('email'));
    if (mb_strlen($message)<2) return new WP_Error('invalid_message','Veuillez préciser votre question.',['status'=>400]);
    $text=mb_strtolower($message);
    if (str_contains($text,'livraison') || str_contains($text,'retrait')) return ['answer'=>'La livraison à domicile coûte 2 000 FCFA. Le retrait OKOUMÉ est gratuit lors de la commande.'];
    if (str_contains($text,'garantie')) return ['answer'=>'La durée de garantie est indiquée sur chaque fiche produit. Nos appareils d’occasion sont présentés avec leurs contrôles et éventuels défauts visibles.'];
    foreach (wc_get_products(['status'=>'publish','limit'=>50,'meta_key'=>'_okoume_enabled','meta_value'=>'yes']) as $product) {
      $name=mb_strtolower($product->get_name()); $words=array_filter(explode(' ',preg_replace('/[^\p{L}\p{N}]+/u',' ',$name)));
      if (count(array_intersect($words,explode(' ',preg_replace('/[^\p{L}\p{N}]+/u',' ',$text))))>=2) return ['answer'=>$product->get_name().' est actuellement proposé à '.wc_price($product->get_price()).'. '.($product->is_in_stock()?'Il est en stock.':'Il n’est plus disponible.')];
    }
    if (!$this->intake_allowed('chat')) return ['answer'=>'Votre demande a déjà été transmise. Notre équipe reviendra vers vous dès que possible.','fallback'=>true];
    $body="Question : {$message}\nE-mail client : ".($email ?: 'Non renseigné')."\nPage : ".esc_url_raw(wp_get_referer() ?: 'PWA OKOUMÉ');
    $saved=$this->save_message('chat','',$email,$body);
    $headers=['Content-Type: text/plain; charset=UTF-8']; if ($email) $headers[]='Reply-To: '.$email;
    $sent=wp_mail('info@find-gabon.com','[OKOUMÉ] Demande chatbot à traiter',$body,$headers);
    if ($saved || $sent) return ['answer'=>'Je n’ai pas encore la réponse. Votre demande a été transmise à notre équipe : elle vous répondra dès que possible.','fallback'=>true];
    return new WP_Error('chat_unavailable','Le service de messagerie est momentanément indisponible.',['status'=>503]);
  }
  private function stripe_checkout_session($order) {
    if (!class_exists('WC_Stripe_API') || !class_exists('WC_Stripe_Helper')) throw new Exception('Le paiement par carte est momentanément indisponible.');
    $items=[]; foreach ($order->get_items('line_item') as $item) { $quantity=max(1,(int)$item->get_quantity()); $items[]=['price_data'=>['currency'=>strtolower(get_woocommerce_currency()),'product_data'=>['name'=>wp_strip_all_tags($item->get_name())],'unit_amount'=>WC_Stripe_Helper::get_stripe_amount((float)$item->get_total()/$quantity)],'quantity'=>$quantity]; }
    if ((float)$order->get_shipping_total()>0) $items[]=['price_data'=>['currency'=>strtolower(get_woocommerce_currency()),'product_data'=>['name'=>'Livraison à domicile'],'unit_amount'=>WC_Stripe_Helper::get_stripe_amount((float)$order->get_shipping_total())],'quantity'=>1];
    $request=['mode'=>'payment','payment_method_types'=>['card'],'line_items'=>$items,'success_url'=>'https://okoume.find-gabon.com/?stripe_session_id={CHECKOUT_SESSION_ID}#/confirmation','cancel_url'=>'https://okoume.find-gabon.com/#/livraison','client_reference_id'=>(string)$order->get_id(),'metadata'=>['okoume_order_id'=>(string)$order->get_id(),'okoume_tracking_code'=>$this->tracking_code($order)],'payment_intent_data'=>['metadata'=>['okoume_order_id'=>(string)$order->get_id()]]];
    $session=WC_Stripe_API::request($request,'checkout/sessions'); if (!empty($session->error)||empty($session->url)||empty($session->id)) throw new Exception(!empty($session->error->message)?$session->error->message:'Impossible de préparer le paiement carte.');
    $order->update_meta_data('_okoume_stripe_checkout_session',(string)$session->id); $order->save(); return $session;
  }
  public function stripe_return($request) { try { $session_id=sanitize_text_field($request->get_param('session_id')); if (!$session_id) return new WP_Error('invalid_session','Session de paiement requise.',['status'=>400]); $session=WC_Stripe_API::request([], 'checkout/sessions/'.rawurlencode($session_id), 'GET'); $order_id=(int)($session->metadata->okoume_order_id ?? 0); $order=wc_get_order($order_id); if (!$order || $order->get_meta('_okoume_stripe_checkout_session')!==$session_id) return new WP_Error('not_found','Paiement introuvable.',['status'=>404]); if (($session->payment_status ?? '')==='paid' && !$order->is_paid()) { $order->payment_complete((string)($session->payment_intent ?? '')); $order->add_order_note('Paiement Stripe confirmé.'); } return ['number'=>$this->tracking_code($order),'paid'=>$order->is_paid(),'status'=>$order->get_status(),'total'=>$order->get_total()]; } catch (Exception $e) { return new WP_Error('stripe_error','La vérification du paiement est indisponible.',['status'=>502]); } }
  public function stripe_webhook() { $payload=file_get_contents('php://input'); $signature=$_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''; $secret=get_option('okoume_stripe_webhook_secret',''); if (!$secret || !preg_match('/t=(\d+).*v1=([a-f0-9]+)/',$signature,$matches) || !hash_equals($matches[2],hash_hmac('sha256',$matches[1].'.'.$payload,$secret))) return new WP_Error('invalid_signature','Signature invalide.',['status'=>400]); $event=json_decode($payload,true); if (($event['type'] ?? '')==='checkout.session.completed') { $object=$event['data']['object'] ?? []; $order=wc_get_order((int)($object['metadata']['okoume_order_id'] ?? 0)); if ($order && !$order->is_paid() && ($object['payment_status'] ?? '')==='paid') { $order->payment_complete((string)($object['payment_intent'] ?? '')); $order->add_order_note('Paiement Stripe confirmé par webhook.'); } } return ['received'=>true]; }
  public function checkout($request) {
    /* Guest checkout remains available. A signed-in checkout must originate from the PWA. */
    $origin_guard=$this->require_trusted_origin(); if (is_wp_error($origin_guard)) return $origin_guard;
    $context=$this->current_session(false); $u=$context ? $context['user'] : false;
    if ($u) { $guard=$this->require_authenticated_mutation(); if (is_wp_error($guard)) return $guard; }
    $items=$request->get_param('items'); $billing=(array)$request->get_param('billing'); if (!is_array($items)||!count($items)||empty($billing['phone'])) return new WP_Error('invalid_order','Panier et téléphone requis.',['status'=>400]);
    /* The browser is never trusted for product quantities.  Consolidate duplicate
       lines and validate the live WooCommerce stock before creating the order. */
    $requested=[]; foreach ($items as $line) { $id=(int)($line['id']??0); $qty=(int)($line['quantity']??0); if ($id<1||$qty<1) return new WP_Error('invalid_quantity','Quantité de produit invalide.',['status'=>400]); $requested[$id]=($requested[$id]??0)+$qty; }
    foreach ($requested as $id=>$qty) { $p=wc_get_product($id); if (!$p||$p->get_meta('_okoume_enabled')!=='yes'||!$p->is_in_stock()) return new WP_Error('unavailable','Un produit du panier n’est plus disponible.',['status'=>409]); if ($p->managing_stock() && (int)$p->get_stock_quantity()<$qty) return new WP_Error('insufficient_stock',sprintf('Stock insuffisant pour « %s » : %d disponible(s).',$p->get_name(),max(0,(int)$p->get_stock_quantity())),['status'=>409]); }
    if (!$this->intake_allowed('checkout',12,10*MINUTE_IN_SECONDS)) return new WP_Error('rate_limited','Trop de tentatives de commande. Réessayez dans quelques minutes.',['status'=>429]);
    $order=wc_create_order(['customer_id'=>$u?$u->ID:0]); foreach ($requested as $id=>$qty) { $p=wc_get_product($id); $order->add_product($p,$qty); }
    $clean=[]; foreach (['first_name','last_name','email','phone','address_1','city'] as $key) $clean[$key]=sanitize_text_field($billing[$key]??''); $order->set_address($clean,'billing'); $order->set_address($clean,'shipping'); $delivery_method=sanitize_key($request->get_param('delivery_method')); if ($delivery_method==='home') { $shipping=new WC_Order_Item_Shipping(); $shipping->set_method_title('Livraison à domicile'); $shipping->set_method_id('okoume_home_delivery'); $shipping->set_total(2000); $order->add_item($shipping); } $payment_method=sanitize_key($request->get_param('payment_method')); if (!in_array($payment_method,['cod','airtel_money','stripe'],true)) return new WP_Error('invalid_payment','Méthode de paiement indisponible.',['status'=>400]); if ($payment_method==='airtel_money') { $order->set_payment_method('airtel_money'); $order->set_payment_method_title('Airtel Money — 077 638 864'); $note='Commande créée depuis le PWA OKOUMÉ. Règlement attendu par Airtel Money au 077 638 864.'; } elseif ($payment_method==='stripe') { $order->set_payment_method('stripe'); $order->set_payment_method_title('Carte bancaire — Stripe'); $note='Commande créée depuis le PWA OKOUMÉ. Paiement carte Stripe en attente.'; } else { $order->set_payment_method('cod'); $order->set_payment_method_title('Paiement à la livraison'); $note='Commande créée depuis le PWA OKOUMÉ. Paiement à la livraison.'; } $order->calculate_totals(); $order->update_status('on-hold',$note); $result=['id'=>$order->get_id(),'number'=>$this->tracking_code($order),'status'=>$order->get_status(),'total'=>$order->get_total()]; if ($payment_method==='stripe') { try { $result['redirect_url']=$this->stripe_checkout_session($order)->url; } catch (Exception $e) { $order->add_order_note('Erreur de préparation Stripe : '.$e->getMessage()); return new WP_Error('stripe_error','Le paiement carte est momentanément indisponible.',['status'=>502]); } } return $result;
  }
}
new Okoume_Headless_Commerce();
