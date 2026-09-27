<?php
/**
 * Plugin Name: OKOUMÉ Headless Commerce
 * Description: Product fields and secured API for the OKOUMÉ PWA.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class Okoume_Headless_Commerce {
  const COOKIE = 'okoume_session';
  const META = ['condition','storage','color','battery','warranty','accessories','technical_controls','defects'];
  public function __construct() {
    add_action('init', [$this, 'register_categories']);
    add_action('woocommerce_product_options_general_product_data', [$this, 'product_fields']);
    add_action('woocommerce_process_product_meta', [$this, 'save_product_fields']);
    add_action('rest_api_init', [$this, 'routes']);
  }
  public function register_categories() {
    foreach (['smartphones'=>'Smartphones','ordinateurs'=>'Ordinateurs','tablettes'=>'Tablettes','gaming'=>'Gaming','accessoires'=>'Accessoires'] as $slug=>$name) {
      if (!term_exists($slug, 'product_cat')) wp_insert_term($name, 'product_cat', ['slug'=>$slug]);
    }
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
    register_rest_route('okoume/v1','/account',[['methods'=>'GET','callback'=>[$this,'account'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/orders',[['methods'=>'GET','callback'=>[$this,'orders'],'permission_callback'=>'__return_true'],['methods'=>'POST','callback'=>[$this,'checkout'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/orders/track',[['methods'=>'GET','callback'=>[$this,'track_order'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/stripe/return',[['methods'=>'GET','callback'=>[$this,'stripe_return'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/stripe/webhook',[['methods'=>'POST','callback'=>[$this,'stripe_webhook'],'permission_callback'=>'__return_true']]);
    register_rest_route('okoume/v1','/chat',[['methods'=>'POST','callback'=>[$this,'chat'],'permission_callback'=>'__return_true']]);
  }
  public function products($request) {
    $args=['status'=>'publish','limit'=>50,'meta_key'=>'_okoume_enabled','meta_value'=>'yes'];
    if ($request['category']) $args['category']=sanitize_title($request['category']);
    return rest_ensure_response(array_map([$this,'product'], wc_get_products($args)));
  }
  public function one_product($request) { $p=wc_get_product((int)$request['id']); return $p && $p->get_meta('_okoume_enabled')==='yes' ? rest_ensure_response($this->product($p)) : new WP_Error('not_found','Produit introuvable',['status'=>404]); }
  private function token($user_id) { $exp=time()+DAY_IN_SECONDS*14; return base64_encode($user_id.'|'.$exp.'|'.hash_hmac('sha256',$user_id.'|'.$exp,wp_salt('auth'))); }
  private function user() { $raw=isset($_COOKIE[self::COOKIE])?base64_decode(sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE]))):''; [$id,$exp,$sig]=array_pad(explode('|',$raw),3,''); if (!$id || $exp<time() || !hash_equals(hash_hmac('sha256',$id.'|'.$exp,wp_salt('auth')),$sig)) return false; return get_user_by('id',(int)$id); }
  private function session($user) { setcookie(self::COOKIE,$this->token($user->ID),['expires'=>time()+DAY_IN_SECONDS*14,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']); return ['id'=>$user->ID,'email'=>$user->user_email,'first_name'=>$user->first_name,'last_name'=>$user->last_name]; }
  private function attempts_key($email) { return 'okoume_auth_'.md5(($_SERVER['REMOTE_ADDR'] ?? '').'|'.$email); }
  private function locked($email) { return (int)get_transient($this->attempts_key($email)) >= 5; }
  private function failed($email) { $key=$this->attempts_key($email); set_transient($key,(int)get_transient($key)+1,15*MINUTE_IN_SECONDS); }
  public function auth($request) {
    $action=$request['action']; if ($action==='logout') { setcookie(self::COOKIE,'',['expires'=>time()-3600,'path'=>'/']); return ['ok'=>true]; }
    $email=sanitize_email($request->get_param('email')); $password=(string)$request->get_param('password'); if (!$email || strlen($password)<8) return new WP_Error('invalid_input','E-mail et mot de passe valide requis.',['status'=>400]); if ($this->locked($email)) return new WP_Error('rate_limited','Trop de tentatives. Réessayez dans 15 minutes.',['status'=>429]);
    if ($action==='register') { if (email_exists($email)) { $this->failed($email); return new WP_Error('exists','Un compte existe déjà pour cet e-mail.',['status'=>409]); } $id=wp_create_user($email,$password,$email); if (is_wp_error($id)) return $id; wp_update_user(['ID'=>$id,'first_name'=>sanitize_text_field($request->get_param('first_name')),'last_name'=>sanitize_text_field($request->get_param('last_name')),'role'=>'customer']); $user=get_user_by('id',$id); }
    else { $user=wp_authenticate($email,$password); if (is_wp_error($user)) { $this->failed($email); return new WP_Error('invalid_login','Identifiants incorrects.',['status'=>401]); } }
    delete_transient($this->attempts_key($email));
    return $this->session($user);
  }
  public function account() { $u=$this->user(); if (!$u) return new WP_Error('unauthorized','Connexion requise.',['status'=>401]); return $this->session($u); }
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
    $key='okoume_chat_'.md5($_SERVER['REMOTE_ADDR'] ?? ''); if ((int)get_transient($key)>=3) return ['answer'=>'Votre demande a déjà été transmise. Notre équipe reviendra vers vous dès que possible.','fallback'=>true];
    $body="Question : {$message}\nE-mail client : ".($email ?: 'Non renseigné')."\nPage : ".esc_url_raw(wp_get_referer() ?: 'PWA OKOUMÉ');
    $sent=wp_mail('info@find-gabon.com','[OKOUMÉ] Demande chatbot à traiter',$body,['Content-Type: text/plain; charset=UTF-8']);
    if ($sent) { set_transient($key,1+(int)get_transient($key),30*MINUTE_IN_SECONDS); return ['answer'=>'Je n’ai pas encore la réponse. Votre demande a été envoyée à notre équipe : elle vous répondra dès que possible.','fallback'=>true]; }
    return ['answer'=>'Je n’ai pas encore la réponse. Vous pouvez nous écrire directement sur WhatsApp au 077 638 864.','fallback'=>true];
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
    $items=$request->get_param('items'); $billing=(array)$request->get_param('billing'); if (!is_array($items)||!count($items)||empty($billing['phone'])) return new WP_Error('invalid_order','Panier et téléphone requis.',['status'=>400]);
    $u=$this->user(); $order=wc_create_order(['customer_id'=>$u?$u->ID:0]); foreach ($items as $line) { $p=wc_get_product((int)($line['id']??0)); $qty=max(1,(int)($line['quantity']??1)); if (!$p||$p->get_meta('_okoume_enabled')!=='yes'||!$p->is_in_stock()) return new WP_Error('unavailable','Un produit du panier n’est plus disponible.',['status'=>409]); $order->add_product($p,$qty); }
    $clean=[]; foreach (['first_name','last_name','email','phone','address_1','city'] as $key) $clean[$key]=sanitize_text_field($billing[$key]??''); $order->set_address($clean,'billing'); $order->set_address($clean,'shipping'); $delivery_method=sanitize_key($request->get_param('delivery_method')); if ($delivery_method==='home') { $shipping=new WC_Order_Item_Shipping(); $shipping->set_method_title('Livraison à domicile'); $shipping->set_method_id('okoume_home_delivery'); $shipping->set_total(2000); $order->add_item($shipping); } $payment_method=sanitize_key($request->get_param('payment_method')); if (!in_array($payment_method,['cod','airtel_money','stripe'],true)) return new WP_Error('invalid_payment','Méthode de paiement indisponible.',['status'=>400]); if ($payment_method==='airtel_money') { $order->set_payment_method('airtel_money'); $order->set_payment_method_title('Airtel Money — 077 638 864'); $note='Commande créée depuis le PWA OKOUMÉ. Règlement attendu par Airtel Money au 077 638 864.'; } elseif ($payment_method==='stripe') { $order->set_payment_method('stripe'); $order->set_payment_method_title('Carte bancaire — Stripe'); $note='Commande créée depuis le PWA OKOUMÉ. Paiement carte Stripe en attente.'; } else { $order->set_payment_method('cod'); $order->set_payment_method_title('Paiement à la livraison'); $note='Commande créée depuis le PWA OKOUMÉ. Paiement à la livraison.'; } $order->calculate_totals(); $order->update_status('on-hold',$note); $result=['id'=>$order->get_id(),'number'=>$this->tracking_code($order),'status'=>$order->get_status(),'total'=>$order->get_total()]; if ($payment_method==='stripe') { try { $result['redirect_url']=$this->stripe_checkout_session($order)->url; } catch (Exception $e) { $order->add_order_note('Erreur de préparation Stripe : '.$e->getMessage()); return new WP_Error('stripe_error','Le paiement carte est momentanément indisponible.',['status'=>502]); } } return $result;
  }
}
new Okoume_Headless_Commerce();
