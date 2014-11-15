<?php 
class Tournament {
	private $fields = array('id', 'creation_date', /*'type' AS*/ 'format', 'name',
		'min_players', 'status','round', 'update_date', 'due_time', 'data') ;
	public $data = null ;
	public $players = array() ;
	public $logs = array() ;
	public $boosters = array() ;
	public $games = array() ;
	private $debug_mode = true ;
	private $observer = null ;
	private $timer = null ;
	public $spectators = null ;
	static $cache = array() ;
	static function get($id, $type='ended_tournament') {
		foreach (Tournament::$cache as $tournament)
			if ( $tournament->id == $id )
				return $tournament ;
		global $db ;
		$tournaments = $db->select("SELECT *, `type` AS 'format' FROM `tournament` WHERE `id` = '$id'");
		if ( count($tournaments) > 0 ) {
			if ( count($tournaments) > 1 ) // bug
				echo count($tournaments)." tournaments found : $id\n" ;
			$t = new Tournament($tournaments[0]) ;
			if ( $t->status > 5 )
				$t->import('ended_tournament') ;
			else
				$t->import('running_tournament') ;
			return $t ;
		}
		return null ;
	}
	static function create($data, $user) {
		if ( property_exists($data, 'id') ) {
			$tournament = Tournament::get($data->id) ;
			if ( $tournament != null )
				return $tournament ;
		}
		$options = Tournament::check_create($data) ;
		if ( is_string($options) )
			return $options ;
		global $db ;
		$data->status = 1 ;
		$data->data = $options ;
		$data->id = $db->insert("INSERT INTO
			`tournament` ( `type`, `name`, `min_players`, `status`, `data` )
			VALUES ( '{$data->format}', '".$db->escape($data->name)."',
			{$data->min_players}, {$data->status},
			'".$db->escape(json_encode($options))."' );") ;
		$tournament = new Tournament($data) ;
		$tournament->log($user->player_id, 'create', $user->nick) ;
		return $tournament ;

	}
	public function __construct($obj=null) {
		global $gameserver ;
		$this->observer = $gameserver ;
		Tournament::$cache[] = $this ;
		foreach ( $this->fields as $field ) {
			if ( ( $obj != null ) && property_exists($obj, $field) )
				$this->$field = $obj->$field ;
			else
				$this->$field = '' ;
		}
		if ( isset($this->creation_date) && ( $this->creation_date == '' ) )
			$this->creation_date = now() ;
		if ( is_string($this->data) )
			$this->data = json_decode($this->data) ;
		$this->spectators = new Spectators() ;
	}
	public function debug($msg) {
		if ( $this->debug_mode )
			echo "Tournament {$this->id} : $msg\n" ;
		return false ; // for return debug(debug message) ;
	}
	public function say($msg) {
		$this->observer->say($msg) ;
	}
	// Accessors
	public function get_player($id, $by='player_id') {
		foreach ( $this->players as $player )
			if ( $player->$by == $id )
				return $player ;
		return null ;
	}
	public function get_booster($player, $number=-1) {
		if ( $number == -1 )
			$number = $this->round ;
		foreach ( $this->boosters as $boost ) {
			if ( ! is_object($boost) ) {
				$this->say('Boost : '.$boost) ;
				continue ;
			}
			if ( $boost->is($player, $number) )
				return $boost ;
		}
		return null ;
	}
	private function get_exts() {
		$exts = array() ;
		if ( ( property_exists($this->data, 'boosters') ) && count($this->data->boosters) > 0 ) {
			global $db_cards ;
			$in = implode("', '", array_unique($this->data->boosters)) ;
			foreach ( $db_cards->select("SELECT * FROM `extension`
				WHERE `se` in ('$in')") as $ext )
				$exts[$ext->se] = $ext ;
		}
		return $exts ;
	}
	// DB Interactions
	private function commit() { // Update all DB fields in param with this object's data
		if ( func_num_args() < 1 )
			return debug('commit() wait at least 1 arg') ;
		$this->update_date = now() ;
		$args = func_get_args() ;
		$args[] = 'update_date' ; // Force updating this field
		global $db ;
		$update = '' ;
		foreach ( $args as $field )
			if ( property_exists($this, $field) ) {
				if ( $update != '' )
					$update .= ', ' ;
				if ( is_object($this->$field) )
					$update .= "`$field` = '".
					$db->escape(json_encode($this->$field))."'" ;
				else
					$update .= "`$field` = '{$this->$field}'" ;
			} else
				return debug('cannot commit '.$field) ;
		$db->query("UPDATE `tournament` SET $update WHERE `id` = '{$this->id}' ; ") ;
	}
	// Log
	public function log($player_id, $type, $value) {
		$line = '{"sender": "'.$player_id.'" , "type": "'.$type.'", "value": '.json_encode($value).', "timestamp": "'.now().'"}' ;
		$line = json_decode($line) ;
		global $db ;
		$value = $db->escape($value) ;
		$line->id = $db->insert("INSERT INTO `tournament_log`
			(`tournament_id` ,`sender` ,`type` ,`value`)
			VALUES ('{$this->id}', '$player_id', '$type', '$value') ; ") ;
		$this->logs[] = $line ;
		return true ;
	}
	public function message($player_id, $message) {
		return $this->log($player_id, 'msg', $message) ;
	}
	// Spectator
	public function register_spectator($user) {
		if ( $this->spectators->get($user->player_id) != null )
			return false ;
		$spectator = $this->spectators->add($user->player_id, $user->nick) ;
		$this->log($spectator->player_id, 'spectactor', $spectator->nick) ;
		$this->send() ;
	}
	// Initialisation
	public function import($type) { // Called on daemon start on each pending/running tournament
		$this->type = $type ;
		// Players
		global $db ;
		$this->players = $db->select("SELECT *
		FROM `registration`
		WHERE
			`tournament_id` = '".$this->id."'
			AND `status` <= 6
		ORDER BY `order` ASC ; ") ;
		foreach ( $this->players as $i => $player )
			$this->players[$i] = new Registration($player, $this) ;
		if ( count($this->players) == 0 ) {
			$this->cancel('No player in import') ;
			return false ;
		}
		// Booosters
		$this->import_boosters() ;
		// Log
		$this->logs = $db->select("SELECT id, sender, type, value, timestamp
			FROM `tournament_log`
			WHERE `tournament_id` = {$this->id}") ;
		foreach ( $this->logs as $log )
			if ( $log->type == 'spectactor' )
				$this->spectators->add($log->sender, $log->value) ;
		// Games
		$games = $db->select("SELECT * FROM `round` WHERE `tournament` = '{$this->id}' ORDER BY `round`") ;
		$round = 0 ;
		foreach ( $games as $game ) {
			if ( $round != $game->round ) {
				$round = $game->round ;
				$this->games[] = array() ;
			}
			$this->games[count($this->games)-1][] = new Game($game, 'tournament', $this) ;
		}
		// Go on
		$left = strtotime($this->due_time) - time() ;
		if ( $left > 0 ) // Some time left in current tournament step
			$this->timer_goon($left) ;
		else
			$this->goon('import') ;
		return true ;
	}
	private function import_boosters() {
		global $db ;
		$this->boosters = array() ;
		foreach ( $db->select("SELECT content, player, number, pick, destination
		                        FROM `booster`
					WHERE `tournament` = {$this->id}") as $boost ) {
			$player = $this->get_player($boost->player, 'order') ;
			if ( $player == null ) {
				$this->say('Player '.$boost->player.' not found in boost import') ;
				continue ;
			}
			$booster = new Booster($this, $player, $boost->number, $boost->pick, $boost->destination) ;
			$booster->import($boost->content) ;
			$this->boosters[] = $booster ;
		}
	}
	public function check_create($data) { // Called when not imported from DB, basic checks
		$options = new stdClass() ;
		// Boosters for limited
		switch ( $data->format ) {
			case 'sealed' :
				$options->clone_sealed = $data->clone_sealed == 'true' ;
			case 'draft' :
				//$card_connection = card_connect() ;
				$options->boosters = array() ;
				foreach ( explode('-', $data->boosters) as $boosts ) {
					$boost = $boosts ;
					$nb = 1 ;
					$expl = explode('*', $boosts) ;
					switch ( count($expl) ) {
						case 0 : 
						case 1 :
							break ;
						case 2 :
							$boost = $expl[0] ;
							$nb = intval($expl[1]) ;
							break ;
						default ;
					}
					$boost = strtoupper($boost) ;
					if ( Extension::get($boost) == null )
						return 'Extension '.$boost.' doesn\'t exist' ;
					else
						for ( $i = 0 ; $i < $nb ; $i++ ) 
							array_push($options->boosters, $boost) ;
				}
				if ( count($options->boosters) < 1 )
					return 'No parsable boosters' ;
				//$this->name .= ' ('.implode($options->boosters, '-').')' ;
				break ;
		}
		// Other options
		if ( is_numeric($data->rounds_duration) )
			$options->rounds_duration = $data->rounds_duration ;
		else {
			global $round_duration ; // Read default value in config file
			$options->rounds_duration = $round_duration ;
		}
		if ( is_numeric($data->rounds_number) )
			$options->rounds_number = $data->rounds_number ;
		else
			$options->rounds_number = 0 ;
		return $options ;
	}
	// User - registration
	public function registered($user) {
		foreach ( $this->players as $i => $player )
			if ( $player->player_id == $user->player_id )
				return $i ;
		return false ;
	}
	public function register($data, $user) {
		if ( $this->status != 1 )
			return debug("Trying to register while in status {$this->status}") ;
		// Basic verifications
		$msg = '' ;
		if ( $data->nick == '' )
			 $msg = 'You must choose a valid nickname' ;
		if ( $this->status != 1 )
			 $msg = 'Tournament '.$this->id.' not registrable ('.$this->status.')' ;
		if ( ( $this->format == 'draft' ) || (  $this->format == 'sealed' ) ) // Limited
			$data->deck = '' ; // Deck will be generated by first tournament's steps
		else // Constructed
			if ( $data->deck == '' )
				 $msg = 'You must select a deck to register to constructed tournaments' ;
		foreach ( $this->players as $player) {
			if ( $player->player_id == $user->player_id )
				 $msg = 'There is already a registered player with ID '.$user->player_id ;
			if ( $player->nick == $data->nick )
				 $msg = 'There is already a registered player with nick '.$data->nick ;
			if ( $player->avatar == $data->avatar )
				 $msg = 'There is already a registered player with avatar '.$data->avatar ;
		}
		if ( $msg != '' ) {
			$user->sendString('{"type": "msg", "msg": "'.$msg.'"}') ;
			return debug("{$player->nick} register : $msg") ;
		}
		// Action
		$this->log($user->player_id, 'register', $data->nick) ;
		$this->players[] = new Registration($data, $this) ;
		$this->send() ;
		if ( count($this->players) >= $this->min_players )
			$this->redirect() ;
		return true ;
	}
	public function unregister($user) {
		if ( $this->status != 1 )
			return debug("{$user->nick} trying to register while in status {$this->status}") ;
		if ( ( $i = $this->registered($user) ) === false ) {
			$this->observer->index->sendString($user, '{"type": "msg", "msg": "'.$user->nick.
				' not found in tournament '.$this->id.'"}') ;
			return debug($user->nick.' not found for unregister') ;
		}
		array_splice($this->players, $i, 1) ;
		$this->log($user->player_id, 'unregister', $user->nick) ;
		if ( count($this->players) == 0 )
			$this->cancel('No registered players anymore') ;
		else
			$this->send() ;
		return true ;
	}
	// Tournament run
	private function set_status($status) { // Set status for tournament and its players
		$this->status = $status ;
		$this->round = 1 ;
		$this->commit('status', 'round') ;
		foreach ( $this->players as $player )
			$player->set_status($status-1) ;
		//$this->send() ;
	}
	public function players_ready() {
		foreach ( $this->players as $player )
			if ( ! $player->ready )
				return false ;
		// All players are ready, don't wait for timer
		$this->goon('players_ready') ;
		return true ;
	}
	private function timer_cancel() {
		if ( $this->timer != null ) {
			$this->timer->cancel() ;
			$this->timer = null ;
			return true ;
		}
		return false ;
	}
	public function timer_goon($delay) { // Run $this->goon() after $delay seconds
		if ( $this->timer_cancel() )
			debug('There is already a timer started') ;
		// Update due
		$this->due_time = now($delay) ;
		$this->commit('due_time') ;
		// Start timer
		$tournament = $this ;
		$this->timer = $this->observer->loop->addTimer($delay, function() use($tournament) {
			$tournament->goon('timer') ;
		}) ;
	}
	public function goon($from='nothing') { // Go one step further in tournament run
		$this->timer_cancel() ;
		$status = $this->status ;
		switch ( $this->status ) {
			case 1 : // Pending
				$this->cancel('Goon with status pending') ;
				break ;
			case 2 : // Called by setready on last player tournament index join
				$this->begin() ;
				break ;
			case 3 : // Drafting
				$cardsleft = $this->draft() ; // Draft procedure
				if ( $cardsleft > 0 ) 
				// Cards left in boosters, rotate
					$this->rotate() ;
				else { // No cards left in boosters
					// Next booster
					$this->round++ ;
					$this->commit('round') ;
					if ( $this->round > count($this->data->boosters) ) { // Was last
						$this->build() ;
						return false ;
					}
					// Update cards left in boosters for timer
					foreach ( $this->boosters as $booster )
						if ( $booster->number == $this->round )
							$cardsleft = max($cardsleft, count($booster->content)) ;
				}
				// Update tournament and send update
				$lastbooster = ($this->round == count($this->data->boosters)) ;
				$this->timer_goon(Tournament::draft_time($cardsleft, $lastbooster)) ;
				// Send new booster to player
				foreach ( $this->players as $player ) {
					$booster = $this->get_booster($player) ;
					$this->observer->draft->broadcast_player($this, $player, json_encode($booster));
				}
				break ;
			case 4 : // Building
				$this->start() ;
				break ;
			case 5 : // Playing
				$this->nextround() ;
				break ;
			case 6 : // Ended
				break ;
			default :
				$this->say('Goon with status '.$this->status) ;
		}
		$this->send() ;
		if ( $this->status != $status ) {
			if ( ( $this->status >= 3 ) && ( $this->status <= 4 ) ) // Draft & sealed
				$this->observer->tournament->broadcast($this, '{"type": "redirect"}') ;
			if ( ( $this->status >= 5 ) && ( $this->status <= 6 ) ) // 
				$this->observer->build->broadcast($this, '{"type": "redirect"}') ;
		}
	}
	public function redirect() { // Tournament : pending -> waiting players
		if ( $this->status != 1 )
			return debug("trying to redirect while in status {$this->status}") ; ;
		// Move from pending to running
		$this->observer->move_tournament($this, 'pending', 'running') ;
		// DB
		shuffle($this->players) ; // Give random numbers to players
		foreach ( $this->players as $i => $player )
			$player->insert($i) ;
		//$this->data->players = $this->players ;
		$this->set_status(2) ;
		$this->log('', 'players', '') ;
		global $wait_duration ;
		$this->timer_goon($wait_duration) ;
		// Broadcast
		$this->send() ;
	}

	public function begin() { // Launch first step for tournament
		// Unicity
		$upool = array() ; // All cards in current tournament's players pools
			//that comes from a "uniq" extension
		switch ( $this->format ) {
			case 'draft' :
				$number = 0 ;
				foreach ( $this->data->boosters as $booster ) {
					$number++ ; // Number of current booster in draft for player
					foreach ( $this->players as $player ) {
						$boost = new Booster($this, $player, $number) ;
						$boost->generate($booster, $upool) ;
						$this->boosters[] = $boost ;
					}
				}
				$this->set_status(3) ;
				$this->timer_goon(Tournament::draft_time()) ;
				$this->log('', 'draft', '') ;
				break ;
			case 'sealed' :
				foreach ( $this->data->boosters as $booster ) {
					$ext_obj = Extension::get($booster) ;
					if ( $ext_obj == null ) {
						$this->say("Extension $ext not found in sealed generation") ;
						continue ;
					}
					foreach ( $this->players as $player ) {
						$booster = $ext_obj->booster($upool) ;
						foreach ( $booster as $card )
							$player->pick($card) ;
							//$player->deck_obj->side[] = $card ;
					}
				}
				foreach ( $this->players as $player )
					$player->summarize(true) ;
				$this->build() ;
				break ;
			default :
				$this->start() ;
		}
	}
	// Draft
	private function draft() {
		$cards = 99 ;
		foreach ( $this->players as $player ) {
			$booster = $this->get_booster($player) ;
			if ( $booster == null )
				$this->say("Can't find booster for {$player->nick}") ;
			else {
				$dest = $booster->destination ;
				$card = $booster->do_pick($booster->pick) ;
				if ( $card == null )
					$this->say($player->nick.' has nothing to pick') ;
				else {
					$player->pick($card, $dest) ;
					$this->observer->draft->broadcast($this, '{"type": "pick", "card": '.json_encode($card).', "dest": "'.$dest.'"}') ;
				}
				$cards = min($cards, count($booster->content)) ;
				if ( count($booster->content) < 1 )
					$booster->delete() ;
			}
		}
		return $cards ;
	}
	private function rotate() {
		$nb_players = count($this->players) ;
		if ( $nb_players > 1 ) { // Only rotate if there are several players
			if ( $this->round & 1 ) { // Second, fourth, etc. booster
				for ( $i = $nb_players-1 ; $i >= 0 ; $i-- ) // From last to first
					$this->switch_booster($i, $i+1) ;
				$this->switch_booster($nb_players, 0) ;
			} else { // First and third boosters
				for ( $i = 0 ; $i < $nb_players ; $i++ )
					$this->switch_booster($i, $i-1) ;
				$this->switch_booster(-1, $nb_players-1) ;
			}
		}
		$this->import_boosters() ;
	}
	private function switch_booster($source, $dest) {
		global $db ;
		$dests = $db->select("SELECT `player` FROM `booster`
		WHERE
			`tournament` = '{$this->id}'
			AND `player` = '$dest'
			AND `number` = '{$this->round}'
		; ") ;
		if ( count($dests) > 0 )
			$this->say("$dest already exists") ;
		else {
			$upd = $db->update("UPDATE `booster` SET `player` = '{$dest}'
			WHERE
				`tournament` = '{$this->id}'
				AND `player` = '$source'
				AND `number` = '{$this->round}'
			; ") ;
			if ( $upd != 1 )
				$this->tournament->say('Error : '.$upd.' boosters switched') ;
		}
	}
	// Build
	private function build() {
		$this->set_status(4) ;
		global $build_duration ;
		$this->timer_goon($build_duration) ;
		$this->log('', 'build', '') ;
	}
	// All tournaments
	static function rounds_number($players_nb) {
		for ( $rounds_nb = 1 ; $rounds_nb < 12 ; $rounds_nb++ ) {
			$min = pow( 2, ( $rounds_nb - 1 ) ) + 1 ;
			$max = pow( 2, $rounds_nb ) ;
			if ( ( $players_nb >= $min ) && ( $players_nb <= $max ) )
				return $rounds_nb ;
		}
		return 0 ;
	}
	public function start() {
		$nbplayers = count($this->players) ;
		if ( $nbplayers < 2 )
			return $this->end() ;
		$theorical = Tournament::rounds_number($nbplayers) ;
		if ( ! is_numeric($this->data->rounds_number) )
			$this->data->rounds_number = $theorical ;
		else	// Set at least the number of rounds implied by players
			$this->data->rounds_number = max($this->data->rounds_number, $theorical) ;
		$this->commit('data') ;
		// Update tournament
		$this->set_status(5) ;
		$this->log('', 'start', '') ;
		// Start first round
		$players = $this->players ; // Copy array
		shuffle($players) ;
		$matches = array() ;
		while ( count($players) > 1 ) {
			$creator = array_shift($players) ;
			$joiner = array_shift($players) ;
			$matches[] = array($creator, $joiner) ;
		}
		while ( count($players) > 0 ) {
			$bye = array_shift($players) ;
			$matches[] = array($bye, null) ;
		}
		$this->start_games($matches) ;
	}
	private function start_games($matches) { // Start game with actual players list
		$games = array() ;
		$table = 1 ;
		$this->send() ; // Workaround for redirection, client must know tournament status
		foreach ( $matches as $match ) {
			$creator = array_shift($match) ;
			$joiner = array_shift($match) ;
			if ( $creator == null ) { // BYE must be joiner, swap if needed
				if ( $joiner == null ) {
					$this->say('Two byes encountering, ignored') ;
					continue ;
				}
				$creator = $joiner ;
				$joiner = null ;
			}
			$game = $this->create_game($table++, $creator, $joiner) ;
			// Redirect players for that game
			foreach ( $this->observer->tournament->getConnections() as $user )
				if (
					( $joiner != null ) // Don't redirect bye
					&& isset($user->tournament)
					&& ( $user->tournament->id == $this->id )
					&& ( $game->isPlayer($user->player_id) )
				)
					$user->sendString('{"type": "redirect", "game": '.$game->id.'}') ;
			// Set BYE's game already finished
			if ( $joiner == null )
				$creator->set_ready(true) ;
			$games[] = $game ;
		}
		$this->games[] = $games ;
		$this->log('', 'round', $this->round) ;
		$this->timer_goon($this->data->rounds_duration*60) ;
	}
	private function create_game($table=0, $creator=null, $joiner=null) {
		$data = new stdClass() ;
		$data->name = $this->format.' '.addslashes($this->name) ;
		$data->name .= ' : Round '.$this->round.' Table '.$table ;
		$data->creator_nick = $creator->nick ;
		$data->creator_id = $creator->player_id ;
		$data->creator_avatar = $creator->avatar ;
		$data->creator_deck = $creator->get_deck()->summarize() ;
		if ( $joiner != null ) {
			$data->joiner_nick = $joiner->nick ;
			$data->joiner_id = $joiner->player_id ;
			$data->joiner_avatar = $joiner->avatar ;
			$data->joiner_deck = $joiner->get_deck()->summarize() ;
			$data->status = 3 ;
		} else {
			$data->status = 7 ;
			$data->creator_score = 2 ;
			$data->joiner_nick = 'BYE' ;
			$data->joiner_id = '' ;
			$data->joiner_avatar = '' ;
			$data->joiner_deck = '' ;
		}
		$data->tournament = $this->id ;
		$data->round = $this->round ;
		$game = new Game($data, 'tournament', $this) ;
		$game->join($data) ;
		return $game ;
	}
	private function round_matches($round=0) {
		if ( count($this->games) == 0 )
			return array() ;
		if ( $round == 0 )
			$round = $this->round ;
		if ( count($this->games) < $round )
			$round = count($this->games) ;
		return $this->games[$round-1] ;
	}
	public function player_match($player_id, $round=0) {
		foreach ( $this->round_matches($round) as $match )
			if ( $match->isPlayer($player_id) )
				return $match ;
		return null ;
	}
	public function match_won($game) {
		if ( ( $game->creator_score > 1 ) || ( $game->joiner_score > 1 ) ) {
			$creator = $this->get_player($game->creator_id) ;
			$joiner = $this->get_player($game->joiner_id) ;
			$creator->set_ready(true) ;
			$joiner->set_ready(true) ;
		}
		$this->send() ;
	}
	public function nextround() {
		foreach ( $this->round_matches() as $game ) { // End games
			$action = $game->addAction('', 'roundend', '') ;
			$this->observer->game->broadcast(json_encode($action), $game) ;
		}
		$this->score_games() ;
		$this->round++ ;
		if ( $this->round <= $this->data->rounds_number ) {
			$this->commit('round') ;
			foreach ( $this->players as $player )
				$player->set_ready(false) ;
			$this->start_games($this->find_player_order()) ;
		} else
			$this->end() ;
	}
	private function score_games() {
		$score = new stdClass() ;
		// A first pass computing players scores is needed before computing opponent w
		foreach ( $this->players as $player )
			$player->get_score() ;
		foreach ( $this->players as $player )
			$score->{$player->player_id} = $player->get_omw() ;
		usort($this->players, array('Tournament', 'players_end_compare')) ;
		// Update rank cache
		foreach ( $this->players as $i => $player )
			$score->{$player->player_id}->rank = $i+1 ;
		$this->data->score = $score ;
		$this->commit('data') ;
	}
	static function players_end_compare($player1, $player2) { // Sent to usort to compare scores from 2 players, for ranking (with tie breakers)
		$result = 0 ;
		// Tie breakers
		if ( $player1->score->matchpoints != $player2->score->matchpoints )
			$result = $player2->score->matchpoints - $player1->score->matchpoints ;
		else {
			if ( 
				! property_exists($player1->score, 'opponentmatchwinpct')
				|| ! property_exists($player2->score, 'opponentmatchwinpct')
				|| ! property_exists($player1->score, 'opponentgamewinpct')
				|| ! property_exists($player2->score, 'opponentgamewinpct')
			)
				return 0 ; // Computed after each round, may crash if called before first round end
			if ( $player1->score->opponentmatchwinpct != $player2->score->opponentmatchwinpct )
				$result = $player2->score->opponentmatchwinpct - $player1->score->opponentmatchwinpct ;
			else
				$result = $player2->score->opponentgamewinpct - $player1->score->opponentgamewinpct ;
		}
		if ( $result != 0 )
			$result = $result / abs($result) ; // Return -1, 0 or 1
		return $result ;
	}
	private function find_player_order() {
		// Players
		$player_ids = array() ;
		foreach ( $this->players as $player )
			$player_ids[] = array("id"=> $player->player_id) ;
		if ( count($player_ids) % 2 != 0 )
			$player_ids[] = array("id"=> '') ; // Bye
		// Played matches
		$matches = array() ;
		foreach ( $this->games as $round )
			foreach ( $round as $game )
				$matches[] = array('team1' => $game->creator_id, 'team2' => $game->joiner_id) ;
		// Generate
		$oRoundGenerator = new RoundGenerator($player_ids, $matches, $this->round);
		$aPossibleMatches = $oRoundGenerator->execute();
		// Return arrays of pairs of players
		$matches = array() ;
		foreach ( $aPossibleMatches as $generated ) {
			$creator = $this->get_player($generated['team1']) ;
			$joiner = $this->get_player($generated['team2']) ;
			$matches[] = array($creator, $joiner) ;
		}
		return $matches ;
	}
	public function end() { // Last round ended normally
		$this->observer->move_tournament($this, 'running', 'ended') ;
		$this->set_status(6) ;
		$this->log($this->players[0]->player_id, 'end', '') ;
		$this->terminate() ;
	}
	public function cancel($reason = 'No reason given') { // Any error occured
		$this->observer->move_tournament($this, substr($this->type, 0, 7), 'ended') ;
		$this->log('', 'cancel', $reason) ;
		$this->set_status(0) ;
		$this->terminate() ;
		debug("Canceled ($reason)") ;
		$this->send() ;
	}
	private function terminate() { // Common between end and cancel
		$this->due_time = now() ;
		$this->commit('due_time') ;
		$this->timer_cancel() ;
	}
	// Websocket communication
	public function send() {
		$this->observer->index->broadcast(json_encode($this)) ;
		$this->observer->tournament->broadcast($this, json_encode($this));
		$this->observer->draft->broadcast($this, json_encode($this));
		$this->observer->build->broadcast($this, json_encode($this));
	}
	// Lib
	static function draft_time($cards=15, $lastround=false) {
		global $draft_base_time, $draft_time_per_card, $draft_lastpick_time ;
		if ( ( $cards < 2 ) && ( ! $lastround ) ) // 1 card left and not last booster
			return $result = $draft_lastpick_time ; // lastpick_time applied
		else
			return  $draft_base_time + ( $draft_time_per_card * $cards ) ;
	}
}