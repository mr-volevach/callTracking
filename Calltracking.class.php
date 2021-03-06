<?php 

class CallTracking {
	
	public $ip = "";
	public $numberInfo = array("number" => null, "numberId" => null);
	public $default_number = "";
	public $cookie = null;
	public $time_active = "";
	public $time_expectation = "";
	public $options = array();

	public function __construct () 
	{
		
		$this->update_phone_table();
		
		$this->options['time_expectation'] = get_option('time_expectation');
		$this->options['time_active'] = get_option('time_active');
		$this->time_active = date("Y-m-d H:i:s", $this->get_active_time());
		$this->time_expectation = date("Y-m-d H:i:s", $this->get_time_expectation());
		$this->default_number = get_option('default_number');
		

		if ($_POST['caller_id']) {
			$this->pushedCall();
			return;
		}

		$this->ip = $this->get_ip_address();
		if(!$this->check_ip_address($this->ip)) {
			$this->numberInfo['number'] = $this->default_number;
			add_shortcode('call_tracking_number', array($this, 'createNumber'));
			return;		
		}

		
		if(!$this->check_cookie()) {
			wp_register_script("get_dynamic_number", plugins_url() . "/callTracking/js/get_number.js", array(), false, true);
			wp_enqueue_script("get_dynamic_number");
			return;
		}
		
		$this->cookie = $this->get_client_id();	
		$this->numberInfo = ($this->search_number_by_client_id($this->cookie)) 
							? $this->search_number_by_client_id($this->cookie) 
							: $this->search_free_phones();
	
		if (!$this->numberInfo) {
			$this->numberInfo['number'] = $this->default_number;
			$this->save_log_default_number();
		}

		add_shortcode('call_tracking_number', array($this, 'createNumber'));

	}
	
	static function install__plugin () 
	{
		global $wpdb;
		$wpdb->query("CREATE TABLE IF NOT EXISTS " . $wpdb->prefix . "calltracking_telephone 
														(	id int PRIMARY KEY auto_increment,
															number_telephone varchar(20),
															id_analytic varchar(255) NOT NULL,
															time_active datetime NOT NULL,
															time_expectation datetime NOT NULL
														)");
		$wpdb->query("CREATE TABLE IF NOT EXISTS " . $wpdb->prefix . "ip_ignore 
														(	id int PRIMARY KEY auto_increment, 
															ip varchar(100)
														)");
		$wpdb->query("CREATE TABLE IF NOT EXISTS " . $wpdb->prefix . "busy_number 
														(	id int PRIMARY KEY auto_increment, 
															date_report datetime, 
															count_number int
														)");
		$wpdb->query("CREATE TABLE IF NOT EXISTS " . $wpdb->prefix . "issued_number 
														(	id int PRIMARY KEY auto_increment,
															cookie varchar(255),
	  														called_did varchar(20) NOT NULL,
	  														caller_id varchar(20) NOT NULL,
	  														date_report datetime NOT NULL,
	  														issued_dynamic_number int(1) NOT NULL,
	 														elapsed_time varchar(20) NOT NULL,
	 														status int(1) NOT NULL
	 													)");
		add_option('default_number', '');
		add_option('secret', '');
		add_option('id_analytic', '');
		add_option('time_active', '0');
		add_option('time_expectation', '0');
		add_option('event', '');
		add_option('event_label', '');
		add_option('type_event', '');
		add_option('context', '');
		add_option('cost', '');
	}
	
	static function uninstall__plugin () 
	{
		global $wpdb;
		$wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . "calltracking_telephone");
		$wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . "ip_ignore");
		$wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . "busy_number");
		$wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . "issued_number");
		delete_option('default_number');
		delete_option('secret');
		delete_option('id_analytic');
		delete_option('time_active');
		delete_option('time_expectation');	
		delete_option('event');
		delete_option('event_label', '');
		delete_option('type_event');
		delete_option('context');
		delete_option('cost');
		delete_option('last_parcing');
	}
	
	private function update_phone_table () 
	{
		global $wpdb;
		$wpdb->query("UPDATE " . $wpdb->prefix . "calltracking_telephone SET id_analytic = '' WHERE time_active < NOW()");
	}
	
	private function get_ip_address () 
	{
		return $_SERVER["REMOTE_ADDR"];
	}
	
	private function check_ip_address ($ip_address) 
	{
		global $wpdb;
		$array_ip = $wpdb->get_col("SELECT ip FROM " . $wpdb->prefix . "ip_ignore");
		return (in_array($ip_address, $array_ip)) ? false : true;
	}
	
	private function check_cookie () 
	{
		return ($_COOKIE['_ga']) ? true : false;
	}
	
	private function get_client_id () 
	{
		return substr($_COOKIE['_ga'], 6);
	}
	
	private function search_number_by_client_id ($client_id) 
	{
		global $wpdb;
		$rezult = $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . "calltracking_telephone WHERE id_analytic = {$client_id} AND time_active > NOW()");
		if($rezult) {
			$this->record_busy_number($rezult->id);
			return array("number" => $rezult->number_telephone, "number_id" => $rezult->id);
		} 
		return false;
	}
	
	private function search_free_phones () 
	{
		global $wpdb;
		$rezult = $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . "calltracking_telephone WHERE time_expectation < NOW() LIMIT 1");
		if($rezult) {
			$this->record_busy_number($rezult->id);
			$this->save_log_dynamic_number($rezult->number_telephone);
			return array("number" => $rezult->number_telephone, "number_id" => $rezult->id);
		} 
		return false;
	}
	
	private function get_active_time () 
	{
		$time = getdate(time());
		$temp = mktime(
					   $time['hours'] + 3, 
					   $time['minutes'] + $this->options['time_active'], 
					   $time['seconds'], 
					   $time['mon'], 
					   $time['mday'], 
					   $time['year']
					   );
		return $temp;
	}

	private function get_time_expectation () 
	{
		$time = getdate(time());
		$temp = mktime(
					   $time['hours'] + 3, 
					   $time['minutes'] + $this->options['time_active'] + $this->options['time_expectation'], 
					   $time['seconds'], 
					   $time['mon'], 
					   $time['mday'], 
					   $time['year']
					   );
		return $temp;
	}
	
	private function record_busy_number ($number_id) 
	{
		global $wpdb;
		$wpdb->update($wpdb->prefix . 'calltracking_telephone', 
									array('time_active' 		=> $this->time_active, 
										  'time_expectation' 	=> $this->time_expectation,
										  'id_analytic' 	 	=> $this->cookie),
									array('id' 				 	=> $number_id));
	}
	
	private function save_log_dynamic_number ($number) 
	{
		global $wpdb;
		$wpdb->query("INSERT INTO " . $wpdb->prefix . "issued_number (called_did, cookie, date_report, issued_dynamic_number	, status) 
					  VALUES ('{$number}', '{$this->cookie}', NOW(), 1, 0)");
	}
	
	private function save_log_default_number () 
	{
		global $wpdb;
		$id_default = $wpdb->get_var("SELECT id FROM " . $wpdb->prefix . "issued_number WHERE date_report = DATE(NOW( )) AND issued_dynamic_number = 0");
		if(!$id_default) {
			$wpdb->query("INSERT INTO " . $wpdb->prefix . "issued_number (called_did, cookie, date_report, issued_dynamic_number, status) 
					  VALUES ('{$this->default_number}', '{$this->cookie}', NOW(), 0, 0)");
		}
	}

	public function createNumber () 
	{
		$numb = $this->numberInfo['number'];
		$tmp = '+' . substr($numb, 0, 1) . ' (' . substr($numb, 1, 3) . ') ' . substr($numb, 4, 3) . '-' . substr($numb, 7, 2) . '-' . substr($numb, 9, 2);
		return $tmp;
	} 

	private function getNumbersForPush () {
		global $wpdb;
		$telephones = $wpdb->get_results("SELECT * FROM wp_calltracking_telephone");
		return ($telephones) ? $telephones : false;
	}

	private function getRequestZadarma () {
		return ($_POST['caller_id']) ? array("caller_id" => $_POST['caller_id'], "called_did" => $_POST['called_did'], "callstart" => $_POST['callstart']) : false;
	}

	private function createPushString ($client_id) {
		$v 	 = "v=1";
		$tid = "&tid=" . get_option('id_analytic');
		$t   = "&t=" . get_option('type_event');
		$ec  = "&ec=" . get_option('context');
		$ea  = "&ea=" . get_option('event');
		$el  = "&el=" . get_option('event_label');
		$ev  = "&ev=" . get_option('cost');
		return "http://www.google-analytics.com/collect?" . $v . $tid . '&cid=' . $client_id . $t . $ec . $ea . $el . $ev;	;
	}

	private function getElapsedTime ($t) {
		$timeStart = strtotime($t . " - " . $this->options['time_active'] . " min");
		$elapsed_time = date("i:s", time() - $timeStart);
		return $elapsed_time;
	}

	private function createReportEmail ($data = array()) {
		$body  = "<table>";
		$body .= "<tr>";
		$body .= "<td>Номер звонящего</td><td>" . $data['zadarma']['caller_id'] . "<td/>";
		$body .= "</tr>";
		$body .= "<tr>";
		$body .= "<td>Закрепленный за абонентом номер</td><td>" . $data['zadarma']['called_did'] . "<td/>";
		$body .= "</tr>";
		$body .= "<tr>";
		$body .= "<td>ID клиента</td><td>" . $data['clientId'] . "<td/>";
		$body .= "</tr>";
		$body .= "<tr>";
		$body .= "<td>Ссылка хита</td><td>" . $data['urlGoogleHit'] . "<td/>";
		$body .= "</tr>";
		$body .= "</table>";
		return $body;	
	}

	private function pushedCall () {
		$zadarmaData = $this->getRequestZadarma();
		if(!$zadarmaData) {
			return;
		}
		
		if($zadarmaData['called_did'] === $this->default_number) {
			$googleAnalitycURL = $this->createPushString('11111111.1111111111');
			file_get_contents($googleAnalitycURL);
			file_get_contents("http://zavodstekko.ru/crm/add_lead.php?" . http_build_query(array(
    			    "title" => "Звонок", 
    			    "name" => "Неизвестно", 
    			    "phone" => $zadarmaData['caller_id'],  
    			    "clientID" => ""
    			)));
			return;
		}

		$phones = $this->getNumbersForPush();
		if (!$phones) {
			return;
		} 
		
		$dataEmail = array('zadarma' => $zadarmaData);

		global $wpdb;

		foreach ($phones as $p) {
			if ($p->number_telephone == $zadarmaData['called_did'] && !empty($p->id_analytic)){
				$wpdb->query("UPDATE " . $wpdb->prefix . "calltracking_telephone SET time_active = '{$this->time_active}', time_expectation = '{$this->time_expectation}' WHERE id_analytic = '{$p->id_analytic}'");
				$urlGoogleHit = $this->createPushString($p->id_analytic);
				$dataEmail['clientId'] = $p->id_analytic; 
				$dataEmail['urlGoogleHit'] = $urlGoogleHit;
				$elapsed_time = $this->getElapsedTime($p->time_active);

				$temp = $this->options['time_active'] + $this->options['time_expectation'];

				$wpdb->query("UPDATE " . $wpdb->prefix . "issued_number SET caller_id = '". $zadarmaData['caller_id'] ."', elapsed_time = '{$elapsed_time}', status = 1 
							  WHERE called_did = '{$p->number_telephone}' AND cookie = '{$p->id_analytic}' AND date_report >= DATE(NOW() - INTERVAL {$temp} minute)");
				file_get_contents($urlGoogleHit);
				file_get_contents("http://zavodstekko.ru/crm/add_lead.php?" . http_build_query(array(
    			    "title" => "Звонок", 
    			    "name" => "Неизвестно", 
    			    "phone" => $dataEmail['zadarma']['caller_id'],  
    			    "clientID" => $p->id_analytic
    			)));
				break;
			}
		}

		$body = $this->createReportEmail($dataEmail);
		
		$header ="Content-type: text/html; charset=\"windows-1251\"";
		$header.="From: Evgen <evgen@mail.ru>";
		$header.="Subject: Event in Google Analytics";
		$header.="Content-type: text/html; charset=\"windows-1251\"";

		mail("sv@computers.net.ua", "Event in Google Analytics", $body, $header);

	}
}

