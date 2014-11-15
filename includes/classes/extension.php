<?php
class Extension {
	public $id ;
	public $se ;
	public $sea ;
	public $name ;
	public $priority ;
	public $release_date ;
	public $bloc ;
	public $uniq ;
	public $data ;
	private $cards = array() ; // All cards
	private $cards_rarity = array() ; // Cards grouped by rarity
	private $cards_tr = array() ; // Transform cards
	static $cache = array() ;
	public function __construct($ext) { // Recieves a line from DB
		$this->id = $ext->id ;
		$this->se = $ext->se ;
		$this->sea = $ext->sea ;
		$this->name = $ext->name ;
		$this->priority = $ext->priority ;
		$this->release_date = $ext->release_date ;
		$this->bloc = $ext->bloc ;
		$this->uniq = $ext->uniq ;
		$this->data = json_decode($ext->data) ;
	}
	private function get_cards() { // Import card list from DB and dispatch by rarity/transformability
		if ( count($this->cards) > 0 )
			return false ;
		global $db_cards ;
		if ( $this->get_data('all', false) )
			$cards = $db_cards->select("SELECT `card`.`name` FROM `card` ORDER BY `card`.`id` ASC") ;
		else
			$cards = $db_cards->select("SELECT `card`.`name`
			FROM `card_ext`, `card`
			WHERE
				`card_ext`.`card` = `card`.`id` AND
				`card_ext`.`ext` = {$this->id}
			ORDER BY `card`.`id` ASC") ;
		foreach ( $cards as $card ) {
			$card_obj = Card::get($card->name, $this->se) ;
			$this->cards[] = $card_obj ;
			if ( $this->get_data('transform', false) 
				&& property_exists($card_obj->attrs, 'transformed_attrs') )
				$dest =& $this->cards_tr ;
			else
				$dest =& $this->cards_rarity ;
			if ( ! array_key_exists($card_obj->rarity, $dest) )
				$dest[$card_obj->rarity] = array() ;
			$dest[$card_obj->rarity][] = $card_obj ;
		}
	}
	private function get_data($property, $value) { // Get a property of data object or a default value
		if ( property_exists($this->data, $property) )
			return $this->data->$property ;
		else
			return $value ;
	}
	private function rand_card($from, &$booster, &$upool) {
		shuffle($from) ;
		foreach ( $from as $card ) {
			if ( in_array($card, $booster) )
				continue ;
			if ( $this->get_data('uniq', false) ) {
				if ( in_array($card, $upool) )
					continue ;
				else
					$upool[] = $card ;
			}
			$result = $card->extend($this->se) ;
			array_unshift($booster, $result) ; // Generated in reverse order
			return $result ;
		}
		echo "No card to add\n" ;
	}
	public function booster(&$upool) {
		$this->get_cards() ;
		$nb_c = $this->get_data('c', 0) ;
		$nb_u = $this->get_data('u', 0) ;
		$nb_r = $this->get_data('r', 0) ;
		$nb_l = $this->get_data('l', 0) ;
		$result = array() ;
		// Booster is generated in reverse order, as foils or transform take a common slot
		// land
		if ( array_key_exists('L', $this->cards_rarity) )
			for ( $i = 0 ; $i < $nb_l ; $i++ )
				$this->rand_card($this->cards_rarity['L'], $result, $upool) ;
		else
			$nb_c += $nb_l ;
		// foil (break unicity)
		global $proba_foil ;
		if ( ( ! $this->get_data('uniq', false) ) && ( rand(1, $proba_foil) == 1 ) ) {
			$nb_c-- ;
			$this->rand_card($this->cards, $result, $upool) ;
		}
		// timeshifted (for TSP)
		if ( $this->get_data('timeshift', false) ) {
			$ext = Extension::get('TSB') ;
			$ext->get_cards() ;
			if ( array_key_exists('S', $ext->cards_rarity) ) {
				$nb_c-- ;
				$this->rand_card($ext->cards_rarity['S'], $result, $upool) ;
			} else
				echo "No timeShift found" ;
		} 
		// transformable (for ISD/DKA)
		if ( $this->get_data('transform', false) ) {
			$r = '' ; // Transform rarity
			$n = rand(1, 14) ;
			if ( $n > 13 )		$r = Extension::r_or_m($this->cards_tr) ;
			elseif ( $n > 10 )	$r = 'U' ;
			else				$r = 'C' ;
			if ( array_key_exists($r, $this->cards_tr) ) {
				$nb_c-- ;
				$this->rand_card($this->cards_tr[$r], $result, $upool) ;
			}
		}
		// rare or mythic
		if ( array_key_exists('R', $this->cards_rarity) ) {
			for ( $i = 0 ; $i < $nb_r ; $i++ )
				$this->rand_card($this->cards_rarity[Extension::r_or_m($this->cards_rarity)]
					, $result, $upool);
		} else
			$nb_c += $nb_r ;
		// uncommons
		if ( array_key_exists('U', $this->cards_rarity) ) {
			for ( $i = 0 ; $i < $nb_u ; $i++ )
				$this->rand_card($this->cards_rarity['U'], $result, $upool) ;
		} else
			$nb_c += $nb_u ;
		// commons (after all other exceptions have been managed)
		if ( array_key_exists('C', $this->cards_rarity) && (count($this->cards_rarity['C']) >= $nb_c) )
			for ( $i = 0 ; $i < $nb_c ; $i++ )
				$this->rand_card($this->cards_rarity['C'], $result, $upool) ;
		else
			echo 'Not enough commons leftin ext '.$ext." ($c/".count($cards['C']).")\n" ;

		return $result ;
	}
	static function r_or_m($cards) {
		global $proba_m ;
		if ( ! array_key_exists('M', $cards) || count($cards['M']) == 0 ) { // No mythics
			if ( ( ! array_key_exists('R', $cards) ) || ( count($cards['R']) == 0 ) ) // And no rares
				return 'S' ; // TSB
			else
				return 'R' ;
		}
		if ( ! array_key_exists('R', $cards) || count($cards['R']) == 0 ) // No rares
			return 'M' ;
		// Rares and Mythics
		if ( rand(1, $proba_m) == 1 )
			return 'M' ;
		return 'R' ;
	}
	static function get($name) {
		foreach (Extension::$cache as $extension)
			if ( $extension->se == $name )
				return $extension ;
		global $db_cards ;
		$name = $db_cards->escape($name) ;
		$exts = $db_cards->select("SELECT * FROM `extension` WHERE `se` LIKE '$name'") ;
		if ( count($exts) > 1 ) // Multiple cards found, bug
			echo count($exts)." extensions found : $name\n" ;
		else if ( count($exts) == 0 ) {
			echo "Extension not found : $name\n" ;
			return null ;
		}
		$ext = new Extension($exts[0]) ;
		Extension::$cache[] = $ext ;
		return $ext ;
	}
	static function fill_cache() {
		foreach ( $db_cards->select("SELECT * FROM `extension`") as $ext ) ;
			Extension::$cache[] = new Extension($ext) ;
	}
}