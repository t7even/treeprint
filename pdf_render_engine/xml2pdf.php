<?php
	header('Content-type: text/html; charset=utf-8');

	$time0 = microtime(true);
	$memory0 = memory_get_usage();

//------------------------------------------------------------------
// Pfade und Standardwerte uebernehmen | ohne daten mit fehlermeldung abbrechen
//------------------------------------------------------------------
	if(!include('inc/config.php')) exit('no config-data'); // Standardwerte
	if(!include('inc/array_functions.php')) exit('functions not defined'); // Funktionen
	if(!include(PATH_TEMPLATE.'/style.php')) exit('template not defined'); // Style, Template
	if(!include('inc/config.php')) exit('no config-data'); // Standardwerte

	// PDF-Klasse sowie custom PDF-Funktionen laden
	require_once('tcpdf/config/lang/eng.php');
	require_once('inc/pdf_functions.php');


	// Switch fuer XML-Quelle
	if(!$_GET['src'])
		$xml_src = DEFAULT_SRC;
	else if(substr($_GET['src'], 0, 4) == 'http')
		$xml_src = $_GET['src'];
	else
		$xml_src = 'src/'.$_GET['src'];


	// Anzeige Vornamen
	$name_display = $a_name_lengths[0];
	$default_date = DEFAULT_DATE;

	// Maximalhoehe der "Box" aus Bestandteilen errechnen
 	if(SHOW_FIRSTNAME)
 		$calc_boxheight = $size['leading'][1]; // Zeilenhoehe Namen
	if(SHOW_LASTNAME)
 		$calc_boxheight += $size['leading'][1]; // Zeilenhoehe Namen
	if(SHOW_TITLE || SHOW_BIRTHDATE || SHOW_DEATHDATE)
 		$calc_boxheight += $size['linespace'][1]; // Zeilenabstand nach Namen
	if(SHOW_TITLE)
 		$calc_boxheight += $size['leading'][2]+$size['linespace'][2]; // Zeilenhoehe "Metadaten" + Zeilenabstand nach Titel
	if(SHOW_BIRTHDATE)
 		$calc_boxheight += $size['leading'][2]; // Zeilenhoehe "Metadaten"
	if(SHOW_DEATHDATE)
 		$calc_boxheight += $size['leading'][2]; // Zeilenhoehe "Metadaten"
	if(SHOW_AGE)
 		$calc_boxheight += $size['linespace'][2]+$size['leading'][2]; // Zeilenabstand nach Daten + Z-Hoehe "Metadaten"

	$size['box']['max_height'] = $calc_boxheight+($size['padding']['box']*2);
	$size['box']['rel']['max_height'] = $size['leading'][2]+($size['padding']['box']*2);



	$time1 = microtime(true);
	$memory1 = memory_get_usage();

//------------------------------------------------------------------
// XML laden
//------------------------------------------------------------------
	if ($xml = simplexml_load_file($xml_src)) {
		if ($xml->generations['type'] == 'a') exit('vorfahren-modus noch nicht implementiert');

		$time2 = microtime(true);
		$memory2 = memory_get_usage();


		//------------------------------------------------------------------
		// Content aufbauen
		//------------------------------------------------------------------

		// Arrays definieren
		$generation = array();
		$people = array();
		$info = array();


//------------------------------------------------------------------
// 1. Durchlauf:
// XML durchgehen und Infos in Array schreiben
//------------------------------------------------------------------

		//------------------------------------------------------------------
		// Aktuelle Generation (jeweils neue Zeile)
		//------------------------------------------------------------------
		foreach ($xml->generations->generation as $xml_generation) {

			// Scheidungsvariable: default = keine
			$generation[ (int)$xml_generation['level'] ] = array(
				'divorce' => 'none'
			);

			// Zaehler ruecksetzen
			$x_pos = 0;
			$i_person = 0;


			//------------------------------------------------------------------
			// Einzelne Personen
			//------------------------------------------------------------------
			foreach ($xml_generation->person as $xml_person) {

				// Personenbreite = Laenge Vorname (bzw. Laenge Nachanme wenn laenger) aber mindestens wie $size['box']['min_width']
				$width_firstname = calcStrLength($style['font']['names'], (string)$xml_person->firstname->full, ($size['font']['names']/10) );
				$width_lastname = calcStrLength($style['font']['names'], (string)$xml_person->lastname->full, ($size['font']['names']/10) );
				$width_person = ( $width_lastname > $width_firstname ) ? $width_lastname : $width_firstname;
				if($width_person < $size['box']['min_width']) $width_person = $size['box']['min_width'];


				// Unique ID von Mutter/Vater suchen (nicht bei allererster Person)
				if( (int)$xml_person['parent_id'] && (int)$xml_generation['level'] >= 1 )
					$parent_uid = search_parent( (int)$xml_person['parent_id'], (int)$xml_generation['level'], (int)$xml_person['pos'], $people);


				// X-Position ueberpruefen und ggf. an Eltern anpassen
				if($x_pos < $people[ (string)$parent_uid ]['x_pos'])
					$x_pos = $people[ (string)$parent_uid ]['x_pos'];

				$people[ (string)$xml_person['uid'] ] = array(
					'x_pos' => $x_pos,	// individuelle Startposition
					'parent_id' => (int)$xml_person['parent_id'], 										// Übergerdnete Person
					'parent_uid' => (string)$parent_uid,															// Übergerdnete Person (Unique ID)

					'fname' => (string)$xml_person->firstname->full,
#					'width_fname' => (int)$xml_person->firstname->full['length'],	// Laenge Vornamen
					'lname' => (string)$xml_person->lastname->full,
#					'width_lname' => (int)$xml_person->lastname->full['length'],  	// Laenge Nachnamen
					'width_person' => $width_person, 																	// Groesse Platzhalter merken

					'space_person' => $width_person + ($size['margin']['box']['x']+($size['padding']['box']*2)),
					'space_family' => 0, 																							// fuer spaetere Berechnung der Laenge aller Namen im nachfolgenden Zweig

					'descendants' => (int)$xml_person['width'], 											// Anzahl/Breite nachfolgende Personen im Zweig

					'position' => (int)$xml_person['pos'], 														// Wievielte Person in Zeile
					'id_in_gen' => (int)$i_person																			// Wievielte Person in Generation
				);


				// Partnergeneration
				if( ($xml_generation['level'] %2) != 0 ){
					// UID des Partners suchen
					$partner_uid = search_parent( (int)$xml_person->relation['partner_id'], (int)$xml_generation['level'], (int)$xml_person['pos'], $people);  // Partner (Unique ID)

					// ID + UID des Partners merken
					$people[ (string)$xml_person['uid'] ]['partner_id'] = (int)$xml_person->relation['partner_id'];
					$people[ (string)$xml_person['uid'] ]['partner_uid'][] = $partner_uid;
					// eigene UID an Partner uebergeben (neuer Array-Eintrag)
					$people[ $partner_uid ]['partner_uid'][] = (string)$xml_person['uid'];
					
						 
					

					// eigenen und Rel-Counter des Partner erhoehen
					$people[ (string)$xml_person['uid'] ]['relations']++;
					$people[ (string)$partner_uid ]['relations']++;

					// Nummer der Beziehung merken
					$people[ (string)$xml_person['uid'] ]['relation_nr'] = $people[ (string)$partner_uid ]['relations'];
				}


				// Personenbreite zu horiz Positionierungs-Koordinate addieren
				$x_pos += $space_person;
				$i_person++;


				// Scheidung ggf. in Mutterarray generation merken
				if($xml_person->relation->type == divorced)
					$generation[(int)$xml_generation['level']]['divorce'] = 'true';

				// alternative Syntax fuer Scheidungen
#				$divorced = array(23,56,4324,4326,usw.)
#				$divorced[] = $person['id'];
#				if(in_array($person['id'],$divorced)) do something;


			}
			//------------------------------------------------------------------
			// Ende Person
			//------------------------------------------------------------------


		}
		//------------------------------------------------------------------
		// Ende Generation
		//------------------------------------------------------------------


		$time3 = microtime(true);
		$memory3 = memory_get_usage();


//------------------------------------------------------------------
// 2. Durchlauf:
// Familienbreiten berechnen
//------------------------------------------------------------------

		// Array umkehren, Schluessel erhalten
		$people = array_reverse($people, true);

		// Familienbreiten berechnen
		foreach($people as $k => $v){
			$parent_id = $people[$k]['parent_uid'];

			// Wenn aktuelle Familienbreite kleiner als aktuelle Personenbreite
			// Familienbreite immer mindestens so breit wie Person selbst setzen
			if($people[$k]['space_family'] < $people[$k]['space_person'])
				$people[$k]['space_family'] = $people[$k]['space_person'];

			// Eltern-Familienbreite um aktuelle Familienbreite erweitern
			$people[ $parent_id ]['space_family'] += $people[ $k ]['space_family'];

		}




if($_GET['debug'] == "all" || $_GET['debug'] == "arrays") {
			// Arrays ausgeben
			print '<pre>';
			print_r($generation);
			print_r($people);
			print '</pre>';
			
			exit;
}

//------------------------------------------------------------------
// 3. Durchlauf:
// Positionen angleichen
//------------------------------------------------------------------

		// Array erneut umkehren, Schluessel erhalten
		$people = array_reverse($people, true);
		$x_pos = 0;

		foreach($people as $k => $v){
			// X-Position je Generation zuruecksetzen
			if($people[$k]['id_in_gen'] == 0)
				$x_pos = 0;

			// X-Position ueberpruefen und ggf. an Vorgaenger anpassen
			if($people[$k]['x_pos'] < $x_pos)
				$people[$k]['x_pos'] = $x_pos;

			$parent_id = $people[$k]['parent_uid'];

			// X-Position ueberpruefen und ggf. an Eltern anpassen
			if($people[$k]['x_pos'] < $people[$parent_id]['x_pos'])
				$people[$k]['x_pos'] = $people[$parent_id]['x_pos'];

			// X-Pos fuer naechsten Durchlauf
			$x_pos = $people[$k]['x_pos']+$people[$k]['space_family'];



			// zuletzt Eltern (abhaengig von Namenslaenge) ueber Kinder zentrieren
			if($people[$k]['descendants'] > 1 && $_GET['align'] != 'left'){

				// Bei Paaren mit einer Ehe
				if($people[$k]['relations'] == '1'){

					// Wenn Person breiter als halbe Familienbreite (zB. Graciela) 
#tmp deaktiviert
#tmp					if( $people[$k]['space_person'] > ($people[$k]['space_family']/2) ){
						
#tmp							$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + (($people[$k]['space_family']-$people[$k]['space_person'])); // Box um Breitendifferenz einruecken
#tmp							if($people[ $people[$k]['partner_uid'][0] ]['relations'] == 1)
#tmp								$people[ $people[$k]['partner_uid'][0] ]['x_pos_pdf'] = $people[$k]['x_pos_pdf']; // Partner ebenfalls einruecken

	/*						
						// Wenn Drittel v Familienbreite kleiner/gleich als halbe Personenbreite
						if(($people[$k]['space_family']/3) <= ($people[$k]['space_person']/2))
							$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + (($people[$k]['space_family']-$people[$k]['space_person'])/2); // Box auf halbe Familienbreite (-halbe Personenbreite) einruecken
				
						// Wenn Familienbreite kleiner/gleich als Personenbreite
						else if(($people[$k]['space_family']) <= $people[$k]['space_person'])
							$people[$k]['x_pos_pdf'] = $people[$k]['x_pos']; // Box nicht einruecken
	*/			
					
#tmp					}else{
						// wenn Person kleiner als halbe Familienbreite
						
						// Wenn neue X_Pos_PDF noch nicht gesetzt
						if(!isset($people[$k]['x_pos_pdf'])){
							$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + (($people[$k]['space_family']-$size['box']['min_width']-$size['margin']['box']['x'])/2); // Box auf halbe Familienbreite (- Mindestboxbreite) einruecken
#							$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + (($people[$k]['space_family']-$people[$k]['space_person'])/2); // Box auf halbe Familienbreite (- Personenbreite) einruecken
							if($people[ $people[$k]['partner_uid'][0] ]['relations'] == 1)
								$people[ $people[$k]['partner_uid'][0] ]['x_pos_pdf'] = $people[$k]['x_pos_pdf']; // Partner ebenfalls einruecken
						}
#tmp					}
	
				}else{
					// Bei mehreren Ehen

					$x_pos_first = $people[ $people[$k]['partner_uid'][0] ]['x_pos'];
					$x_pos_last = $people[ $people[$k]['partner_uid'][ $people[$k]['relations']-1 ] ]['x_pos'];

#############################################################################
/*
					$people[$k]['rel_first_width'] = $people[ $people[$k]['partner_uid'][0] ]['space_family'];
					$people[$k]['rel_last_width'] = $people[ $people[$k]['partner_uid'][ $people[$k]['relations']-1 ] ]['space_family'];
					
					
					$people[$k]['x_pos_first'] = $people[ $people[$k]['partner_uid'][0] ]['x_pos'];
					$people[$k]['$last_rel'] = $people[$k]['partner_uid'][ $people[$k]['relations']-1 ];
#					$people[$k]['$last_rel'] = $people[ $people[$k]['partner_uid'][0] ]['x_pos'];
#					$people[$k]['$x_pos_last'] = $people[ $people[$k]['partner_uid'][ $people[$k]['relations']-1 ] ]['x_pos'];
					$people[$k]['$x_pos_last'] = $people['15315-3-4'];
					#$people[$k]['$x_pos_last'] = $people[$k]['relations']-1;
*/
##################################################################################################					
				
#					$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + ( (($x_pos_last-$x_pos_first)/2) - $people[$k]['width_person']); // Box um Breitendifferenz einruecken
#					$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + ( (($x_pos_last-$x_pos_first)/2) - ($people[$k]['width_person']/2)); // Box um Breitendifferenz einruecken					
// das wär das korrekte, wenn die zweite xpos schon besteht
#####					$people[$k]['x_pos_pdf'] = ( (($x_pos_last-$x_pos_first)/2) - ($people[$k]['width_person']/2)); // Box auf Mitte von Partnerpositionen setzen					
#					$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + (($x_pos_last-$x_pos_first)/2); // Box um Breitendifferenz einruecken
####					$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + (($people[$k]['space_family']-$people[$k]['width_person'])/2); // Box um Breitendifferenz einruecken					

// gleiche Behandlung wie bei Einzelehen
					$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + (($people[$k]['space_family']-$size['box']['min_width']-$size['margin']['box']['x'])/2); // Box auf halbe Familienbreite (- Mindestboxbreite/Padding rechts) einruecken

#					$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + ($people[$k]['space_family']/2); // Box um halbe Familienbreite  einruecken					
#					$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + ((($x_pos_last-$x_pos_first)/2+($people[$k]['space_family']-$people[$k]['width_person']))/2); // Box um Breitendifferenz einruecken					

/*
					if( $people[$k]['width_person'] > ($people[$k]['space_family']/2) ){
						// Wenn Person breiter als halbe Familienbreite (zB. Graciela) 
						$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + (($people[$k]['space_family']-$people[$k]['width_person'])); // Box um Breitendifferenz einruecken
					}else{
						// wenn Person kleiner als halbe Familienbreite
						$people[$k]['x_pos_pdf'] = $people[$k]['x_pos'] + (($people[$k]['space_family']-$size['box']['min_width'])/2); // Box auf halbe Familienbreite (- halbe Mindestboxbreite) einruecken
					}
*/
					
				}

			}

		}


if($_GET['debug'] == "all" || $_GET['debug'] == "time") {
			$time4 = microtime(true);
			$memory4= memory_get_usage();


			$time5 = microtime(true);
			$memory5 = memory_get_usage();

			// Arrays ausgeben
			print '<pre>';
			print_r($generation);
			print_r($people);
			print '</pre>';


			$time6 = microtime(true);
			$memory6 = memory_get_usage();

			// Zeitmessung
			print '<h4>Zeit</h4>';
			print '<p>';
			print 'Includes einlesen: '.round(($time1 - $time0),3).' s.<br/>';
			print 'XML aufrufen: 			'.round(($time2 - $time1),3).' s.<br/>';
			print 'XML verarbeiten: 	'.round(($time3 - $time2),3).' s.<br/>';
			print 'Breiten berechnen: '.round(($time4 - $time3),3).' s.<br/>';
			print 'Array wieder umdrehen: '.round(($time5 - $time4),3).' s.<br/>';
			print 'Arrays ausgeben: 	'.round(($time6 - $time5),3).' s.';
			print '</p>';

			$time = microtime(true) - $time0;
			print '<p>Gesamt:'.round($time,3).' s.</p>';


			// Speichermessung
			print '<h4>Speicher</h4>';
			print '<p>';
			print 'Includes einlesen: 		'.round(($memory1 / 1024 / 1024),3).' MB<br/>';
			print 'XML aufrufen: 					'.round(($memory2 / 1024 / 1024),3).' MB<br/>';
			print 'XML verarbeiten: 			'.round(($memory3 / 1024 / 1024),3).' MB<br/>';
			print 'Breiten berechnen:			'.round(($memory4 / 1024 / 1024),3).' MB<br/>';
			print 'Array wieder umdrehen: '.round(($memory5 / 1024 / 1024),3).' MB<br/>';
			print 'Arrays ausgeben:			 	'.round(($memory6 / 1024 / 1024),3).' MB';
			print '</p>';

			print '<p>';
			print 'Gesamt: 								'.round((memory_get_usage() /1024 /1024),3).' MB<br/>';
			print 'Gesamt (real size): 		'.round((memory_get_usage(true) /1024 /1024),3).' MB';
			print '</p>';

			print '<p>';
			print 'Peak: 									'.round((memory_get_peak_usage() /1024 /1024),3).' MB<br/>';
			print 'Peak (real size): 			'.round((memory_get_peak_usage(true) /1024 /1024),3).' MB';
			print '</p>';

		}else{

//------------------------------------------------------------------
// 4. Durchlauf:
// PDF zeichnen
//------------------------------------------------------------------

			// Bei Nachfahrenbaum
			$keys = array_keys($people);
			//								 Familienbreite Urvater/-mutter			 + (pro Person zusätzlich 1*BoxMarginX, 2*BoxPadding)																																 + Margins links/rechts
#			$family_maxwidth = $people[ $keys[0] ]['space_family'] + (((int)$xml->vars->count->columns.$content_stats[3]) * ($size['margin']['box']['x']+($size['padding']['box']*2))) + ($size['margin']['left']+$size['margin']['right']);
			$family_maxwidth = $people[ $keys[0] ]['space_family'] + ($size['margin']['left']+$size['margin']['right']) - $size['margin']['box']['x'];

			//------------------------------------------------------------------
			// Seitengröße
			//------------------------------------------------------------------
			include('inc/config_page.php');

					
			// Scaling 1 - alle allgemeinen Masse mit Skalierungsfaktor multplizieren
			foreach ($size as $k1 => $v1) {
   			if(is_array($v1)){
   				foreach ($v1 as $k2 => $v2) {
        		if(is_array($v2)){
   						foreach ($v2 as $k3 => $v3) {
        				if(is_array($v3)){
   								foreach ($v3 as $k4 => $v4) {
        						$size[$k1][$k2][$k3][$k4] = $v4 * $scaling;
    							}
   							}else{
   								$size[$k1][$k2][$k3] = $v3 * $scaling;
   							}
    					}
   					}else{
	        		$size[$k1][$k2] = $v2 * $scaling;
   					}
    			}
   			}else{
   				$size[$k1] = $v1 * $scaling;
   			}
			}
			
			// Scaling 2 - alle User-Masse mit Skalierungsfaktor multplizieren
			foreach ($people as $k1 => $v1) {
				foreach ($v1 as $k2 => $v2) {
   				if($k2 == 'x_pos' || $k2 == 'width_person' || $k2 == 'space_person' || $k2 == 'space_family' || $k2 == 'x_pos_pdf')
	   				$people[$k1][$k2] = $v2 * $scaling;
				}
			}
			

			
// DEBUG --------------------------------------------------------------
			if($_GET['debug'] == "all" || $_GET['debug'] == "info") {
				// Groessen ausgeben
				echo 'Scale: '.$scaling.'<br>';
				echo 'XML: '.$family_maxwidth.' x '.$file_height.'<br>';
				echo 'Seite: '.$page_width.' x '.$page_height.'<br>';
				
				print '<pre>';
				print_r($size);
				print_r($style);
#				print_r($_SERVER);
				print_r($people);
				print '</pre>';
				exit;
			}
// end DEBUG ---------------------------
			
			
			
			//------------------------------------------------------------------
			// Texte fuer Header+Footer
			//------------------------------------------------------------------
			if($_GET['lang'] == 'en')
				include('inc/description_en.php');
			else
				include(DEFAULT_CONTENT);



			// PDF initialisieren
			$pdf = new XML2PDF(PDF_PAGE_ORIENTATION, PDF_UNIT, array($page_height,$page_width), true, 'UTF-8', false);

			// Set document information
			$pdf->SetAuthor(PDF_AUTHOR); //PDF_AUTHOR (default: t7even)
			$pdf->SetTitle($content_title); //PDF_HEADER_TITLE
			$pdf->SetSubject('');
			$pdf->SetKeywords('');
			$pdf->SetCreator(PDF_CREATOR); //PDF_CREATOR (default: treeprint.com)
#			$pdf->SetProtection('modify');
			$pdf->SetDisplayMode(75);

#			$pdf->SetPDFVersion('1.8');

			// Header
			if($_GET['final'] || $_GET['save'] )
				$pdf->SetHeaderData('','', $content_title, $content_stats);
			elseif($_GET['debug'] || $_GET['dev'] )
				$pdf->SetHeaderData('','', $content_title.', '.(int)($scaling * 100).'%, '.$_SERVER[SCRIPT_URI].'?'.$_SERVER[QUERY_STRING].'&save=1', $content_stats);
			else 
				$pdf->SetHeaderData('','', $content_title, $content_stats);
			$pdf->setHeaderMargin($size['margin']['header']);

			// Footer
			$pdf->setFooterFont(array($style['font']['footer'], 'I', $size['font']['footer']));
			define('FOOTER_DATA', $content_footer);
			define('FOOTER_OFFSET_X_LOGO', $page_width-$size['margin']['right']-$size['size']['logo']['x']);
			define('FOOTER_OFFSET_Y_LOGO', $page_height-$size['margin']['footer']-$size['size']['logo']['y']);

			// Page
			$pdf->SetMargins($size['margin']['left'], $size['margin']['top'], $size['margin']['right']);
			$pdf->SetAutoPageBreak(off, $size['margin']['bottom']);
			$pdf->SetCellPadding($size['padding']['cell']); // Einzug fuer Boxes, Names, Dates
			$pdf->SetTextColor($style['font']['color']['default']); // Schriftfarbe

			$pdf->AddPage(); //definiert Seite inkl Header und Footer

// DEBUG ----------------------------------------------
// Testing: EPS-Bild fuer genaue Positionierung
			if($_GET['src'] == 'andreas_schmidt_single.xml')
				$pdf->ImageEps('andreas.ai', $size['margin']['left']-2.5+0.235+0.02+0.242, $size['margin']['top']+4.837-0.019-0.25);
// end ------------------------------------------------
				
				
			//------------------------------------------------------------------
			// Content
			//------------------------------------------------------------------
			//Start-Nullpunkt fuer Personen
			$posX = $size['margin']['left']+$size['offset']['box']['x']; $posY = $size['margin']['top']+$size['offset']['box']['y'];
			$i_r = 0;

			// Nullpunkt markieren
#			$pdf->Line($posX, $posY, $posX+3, $posY);
#			$pdf->Line($posX, $posY, $posX, $posY+3);


			//------------------------------------------------------------------
			// Aktuelle Generation (jeweils neue Zeile)
			//------------------------------------------------------------------
			foreach ($xml->generations->generation as $xml_generation) {

				if($generation[ (int)$xml_generation['level'] ]['divorce'] == 'true'){
					$gen_divorced = TRUE;
					$height_reldate = ($size['box']['rel']['max_height']+$size['leading'][2]);
				}else{
					$gen_divorced = FALSE;
					$height_reldate = $size['box']['rel']['max_height'];
				}

#				$posY = $posY+$size['margin']['box']['y'];


				//------------------------------------------------------------------
				// Einzelne Personen positionieren und ausgeben
				//------------------------------------------------------------------
				foreach ($xml_generation->person as $xml_person) {

    			// Personen-Array übergeben
    			$person = $people[ (string)$xml_person['uid'] ];

					// genaue horiz. Position im PDF anhand XML-Pos errechnen
					if($person['x_pos_pdf'])
#						$posX = ($size['margin']['left']+$person['x_pos_pdf']+($xml_person['pos']*($size['margin']['box']['x']+($size['padding']['box']*2))));
						$posX = ($size['margin']['left']+$person['x_pos_pdf']);
					else
#						$posX = ($size['margin']['left']+$person['x_pos']+($xml_person['pos']*($size['margin']['box']['x']+($size['padding']['box']*2))));
						$posX = ($size['margin']['left']+$person['x_pos']);

/*
					// dabei Eltern ueber Kinder zentrieren
					if($person['descendants'] > 1 && $_GET['align'] != 'left'){
						// Korrektur bei langen Elternnamen
						
						// Wenn 80% v Familienbreite kleiner als Personenbreite
						if(($person['space_family']/3) < ($person['width_person']/2))
#							$posX = $posX + (($person['space_family']-$person['width_person'])/2); // Box auf halbe Familienbreite (-halbe Personenbreite) einruecken
#							$posX = $posX + ($person['space_family']/2); // Box auf halbe Familienbreite einruecken
#							$posX = $posX + (($person['space_family']-$size['box']['min_width'])/2)-($person['width_person']-$person['space_family']/2);
#							$posX = $posX + (($person['space_family']-$person['width_person'])/2)-($person['width_person']-$person['space_family']/2);
							$posX = $posX + (($person['space_family']-$person['width_person'])/2); // Box auf halbe Familienbreite (-halbe Personenbreite) einruecken
						// Wenn Familienbreite kleiner/gleich als Personenbreite
						else if(($person['space_family']) <= $person['width_person'])
#							$posX = $posX + (($person['space_family']-$person['width_person'])/2); // Box auf halbe Familienbreite (-halbe Personenbreite) einruecken
							$posX = $posX; // Box nicht einruecken
						// Ansonsten (Familienbreite groesser als Personenbreite)
						else
#							$posX = $posX + ($person['space_family']/2); // Box auf halbe Familienbreite einruecken
#							$posX = $posX + (($person['space_family']-$person['width_person'])/2); // Box auf halbe Familienbreite (-halbe Personenbreite) einruecken
							$posX = $posX + (($person['space_family']-$size['box']['min_width'])/2); // Box auf halbe Familienbreite (- halbe Mindestboxbreite) einruecken
					}
*/					


					// PERSON ausgeben
					// Personendaten checken
					/*
					$xml_person->firstname->$name_display; //default namen
					$xml_person->firstname->$a_name_lengths[4]; //
					$xml_firstname = $xml_person->firstname;
					if($xml_firstname->length[0])
					*/
					
					$xml_firstname = $xml_person->firstname->$name_display;
					#$p_firstname = ($xml_firstname->length[0]) > 35) ? $xml_firstname->$a_name_lengths[0] : $xml_person->firstname->$a_name_lengths[4];
					$p_firstname = ($xml_firstname['length'] == '0') ? 'unknown' : $xml_firstname;
					$p_firstname_vague = ($xml_person->firstname['vague'] == '1') ? '1' : '0';
					
					$p_lastname = ($xml_person->lastname->full['length'] == '0') ? 'unknown' : $xml_person->lastname->full;
					$p_lastname_vague = ($xml_person->lastname['vague'] == '1') ? '1' : '0';
					$p_birth = ($xml_person->birth['length'] == '0') ? 'unknown' : $xml_person->birth->$default_date;
					$p_death = ($xml_person->death['length'] == '0') ? 'unknown' : $xml_person->death->$default_date;
#					$p_age = ($xml_person->age['length'] == 0) ? 'unknown' : $xml_person->age;
					$p_wedding = ($xml_person->relation->wedding['length'] == '0') ? 'unknown' : $xml_person->relation->wedding->$default_date;
					$p_divorce = ($xml_person->relation->divorce['length'] == '0') ? 'unknown' : $xml_person->relation->divorce->$default_date;

					// Wenn Person == 'Partner', Person mit Beziehungsdaten ausgeben (inkl ggf. Symbole), sonst nur Person
					if( ($xml_generation['level'] %2) != 0 )
					  //    PrintPerson( $posX, $posY, $width,                  $sex = 'm',       $firstname,   $fname_vague,				 $lastname   	$lname_vague				$birthdate, $deathdate, $age,         $partner, $type = 'none',          $marriagedate, $divorcedate, $gen_divorced = FALSE){
						$pdf->PrintPerson( $posX, $posY, $person['width_person'], $xml_person->sex, $p_firstname, $p_firstname_vague, $p_lastname, $p_lastname_vague, $p_birth, $p_death, $xml_person->age, TRUE, $xml_person->relation->type, $p_wedding, $p_divorce, $gen_divorced );
					else
						$pdf->PrintPerson( $posX, $posY, $person['width_person'], $xml_person->sex, $p_firstname, $p_firstname_vague, $p_lastname, $p_lastname_vague, $p_birth, $p_death, $xml_person->age, FALSE );


					// BEZIEHUNGSLINIEN zeichnen
					// Wenn Ehepartner von Person mehrere Ehen hatte && aktuelle Person nicht erster Partner
					if( $people[ $person['partner_uid'][0] ]['relations'] > 1 && $person['relation_nr'] > 1){

							$pdf->SetLineStyle(array('width' => $size['line']['love'], 'cap' => 'round', 'join' => 'round', 'dash' => $size['line']['love_dash']['dot'].','.$size['line']['love_dash']['gap'], 'color' => array(LINECOLOR_LOVE)));
#			 				$pdf->Line($posX-5, $posY+4.2, $posX-35, $posY+4.2);
#			 				$pdf->Line($rlines[$i_r][0]+1, $rlines[$i_r][1]+1, $rlines[$i_r][0]+25, $rlines[$i_r][1]+1);
#							$pdf->Cell('', '', 'BAM '.$i_r, 0, 1, TEXTALIGN);
#							$pdf->Cell('', '', $xml_person->firstname->full, 0, 1, TEXTALIGN);
#							$pdf->Cell('', '', $posX.'/'.$posY, 0, 1, TEXTALIGN);
#							$pdf->Cell('', '', $xml_person['uid'], 0, 1, TEXTALIGN);
#			 				$pdf->Line($rlines[ (int)$i_r-1 ][0]+1, $rlines[(int)$i_r-1 ][1]+1, $rlines[$i_r][0]+25, $rlines[$i_r][1]+1);


			 				//Linienlaenge ueberpruefen
			 				if( (($posX-$size['padding']['box']-$size['offset']['line']['rel']['x'])-($rlines[ (int)$i_r-1 ][0]+$size['box']['min_width']+($size['padding']['box']*2)+$size['offset']['line']['rel']['x'])) >= $size['length']['line']['rel']){
#				 				$pdf->Line($posX-($size['padding']['box']*3) , $posY+$size['padding']['box']+($size['leading'][2]/2), $posX-($size['padding']['box']*3)-30, $posY+$size['padding']['box']+($size['leading'][2]/2));
#			 					$pdf->Line($rlines[ (int)$i_r-1 ][0]+$size['box']['min_width']+$size['padding']['box']+$size['margin']['box']['x']+$size['offset']['line']['rel']['x'], $rlines[(int)$i_r-1 ][1]+$size['offset']['line']['rel']['y'], $posX-$size['padding']['box']-$size['margin']['box']['x']-$size['offset']['line']['rel']['x'], $posY+$size['offset']['line']['rel']['y']);
			 					$pdf->Line($rlines[ (int)$i_r-1 ][0]+$size['box']['min_width']+($size['padding']['box']*2)+$size['offset']['line']['rel']['x'], $rlines[(int)$i_r-1 ][1]+$size['margin']['box']['y']+$size['padding']['box']+($size['leading'][2]/2)+$size['offset']['line']['rel']['y'], $posX-$size['padding']['box']-$size['offset']['line']['rel']['x'], $posY+$size['margin']['box']['y']+$size['padding']['box']+($size['leading'][2]/2)+$size['offset']['line']['rel']['y']);

		// Testing - Nummer ausgeben
#				 				$pdf->SetXY($posX-5,$posY);
#								$pdf->Cell('', '', 'byb', 0, 1, TEXTALIGN);
			 				}
			 				else{
		// Testing - Nummer ausgeben
#				 				$pdf->SetXY($posX-5,$posY);
#								$pdf->Cell('', '', 'bzb', 0, 1, TEXTALIGN);
							}

					}


					//------------------------------------------------------------------
					// Verwandschaftslinien
					//------------------------------------------------------------------

				 	// Linienstaerke (Bluts)Verwandschaft festlegen
				 	$pdf->SetLineWidth($size['line']['blood']);
				 	$pdf->SetLineStyle(array('width' => $size['line']['blood'], 'cap' => 'round', 'join' => 'round', 'dash' => LINETYPE_BLOOD, 'color' => array(LINECOLOR_BLOOD)));

	 				// Zur Vereinfachung Variablennamen aus style uebersetzen
	 				$cX = $size['offset']['line']['children']['x'];
			 		$cY = $size['offset']['line']['children']['y'];
			 		$vl = $size['length']['line']['vertical'];

				 	// bei 'Kindern': Linien zeichnen (nur bei der allerersten Person (=Urahn) nicht)
			 		if(($xml_generation['level'] %2) == 0 && $xml_generation['level'] > 0) {

						// Obere senkrechte Linien zwischen Eltern + Kindern zeichnen
						// (aus aufgezeichneten Koordinaten von Partnern)
			 			if(isset($width[ intval($xml_person['pos']) ])){
			 				$line_coords = $vlines[ $people[ (string)$xml_person['uid'] ]['parent_uid'] ];

#			 				$pdf->SetLineStyle(array('width' => $size['line']['blood'], 'cap' => 'round', 'join' => 'round', 'dash' => LINETYPE_BLOOD, 'color' => array((int)$xml_person['pos']*10,(int)$xml_person['width']*10,(int)$xml_person['pos']*5)));
#			 				$pdf->Line($vlines[$i_k][0], $vlines[$i_k][1], $vlines[$i_k][2], $vlines[$i_k][3]);
			 				$pdf->Line($line_coords[0], $line_coords[1], $line_coords[2], $line_coords[3]);
// Testing
#			 				$pdf->SetXY($line_coords[0]+1,$line_coords[1]+1);
#							$pdf->Cell('', '', $xml_person->firstname->full, 0, 1, TEXTALIGN);
#							$pdf->Cell('', '', $vlines[$i_k][4], 0, 1, TEXTALIGN);
#							$pdf->Cell('', '', $line_coords[4], 0, 1, TEXTALIGN);

			 				$i_k++;
			 			}

				 		// Untere senkrechte Linie zwischen Eltern + Kind zeichnen
#		 				$pdf->SetLineStyle(array('width' => $size['line']['blood'], 'cap' => 'round', 'join' => 'round', 'dash' => LINETYPE_BLOOD, 'color' => array(0,0,255)));
			 			$pdf->Line($posX+$size['padding']['box']+$cX, $posY+$cY-$vl, $posX+$size['padding']['box']+$cX, $posY+$cY);


			 			// Wenn Familie mit min. 2 Kindern:
			 			// Koordinaten fuer waagrechte Linien merken (fuer spaeteren Durchlauf)
			 			if($width[intval($xml_person['pos'])]>1) {
			 				$hlines[$i_h][1] = $posX+$size['padding']['box']+$cX;
			 				$hlines[$i_h][2] = $posY-$vl;
#			 				$hlines[$i_h][2] = $posY-$vl;
			 				$parentwidth	= $width[intval($xml_person['pos'])]+$xml_person['pos'];
						}
						if($parentwidth == ($xml_person['pos']+$xml_person['width'])) {
			 				$hlines[$i_h][3] = $posX+$size['padding']['box']+$cX;
			 				$hlines[$i_h][4] = $posY-$vl;
#			 				$hlines[$i_h][4] = $posY-$vl;
			 				unset($parentwidth);
				 			$i_h++;
			 		 	}
			 		}


					// Zur Vereinfachung Variablennamen aus style uebersetzen
					$cX = $size['offset']['line']['partner']['x'];
					$cY = $size['offset']['line']['partner']['y'];
					$vl =	$size['length']['line']['vertical'];

				 	// wenn aktuelle Person = Partner
			 		if(($xml_generation['level'] %2) != 0) {
						// Koordinaten fuer obere senkrechte Linie zwischen Eltern + Kind merken (fuer spaeteren Durchlauf)
#				 		$vlines[$i_v] = array($posX+$cX, $posY+$cY+$size['box']['max_height']+$size['margin']['box']['y'], $posX+$cX, $posY+$cY+$size['box']['max_height']+$size['margin']['box']['y']+$vl);
#				 		$vlines[$i_v'] = array($posX+$cX, $posY+$height_reldate+$size['box']['max_height']+$cY, $posX+$cX, $posY+$height_reldate+$size['box']['max_height']+$cY+$vl);
#						$i_v++;
#				 		$vlines[ (string)$xml_person['uid'] ] = array($posX+$cX, $posY+$height_reldate+$size['box']['max_height']+$cY, $posX+$cX, $posY+$height_reldate+$size['box']['max_height']+$cY+$vl,$xml_person->firstname->full);
#				 		$vlines[ (string)$xml_person['uid'] ] = array($posX+$cX, $posY+$height_reldate+$size['box']['max_height']+$size['margin']['box']['y']+$cY, $posX+$cX, $posY+$height_reldate+$size['box']['max_height']+$size['margin']['box']['y']+$cY+$vl);
				 		$vlines[ (string)$xml_person['uid'] ] = array($posX+$size['padding']['box']+$cX, $posY+$size['margin']['box']['y']+$height_reldate+$size['margin']['box']['y']+$size['box']['max_height']+$size['margin']['box']['y']+$cY, $posX+$size['padding']['box']+$cX, $posY+$size['margin']['box']['y']+$height_reldate+$size['margin']['box']['y']+$size['box']['max_height']+$size['margin']['box']['y']+$cY+$vl);

						// Breite von Eltern (als Wert) zusammen mit Position (als Schluessel) in Array an Kind uebergeben
						$width[ intval($xml_person['pos']) ] = intval($xml_person['width']);
					}


					//------------------------------------------------------------------
					// Beziehungslinien
					//------------------------------------------------------------------

					$crX = $size['offset']['line']['rel']['x'];
					$crY = $size['offset']['line']['rel']['y'];

				 	// Linienstil Beziehung festlegen
				 	$pdf->SetLineStyle(array('width' => $size['line']['love'], 'cap' => 'round', 'join' => 'round', 'dash' => $size['line']['love_dash']['dot'].','.$size['line']['love_dash']['gap'], 'color' => array(LINECOLOR_LOVE)));

				 	// wenn aktuelle Person == Partner
			 		// Linienkoordinaten unten (zu Kind) merken (fuer spaeteren Durchlauf)
				 	if(($xml_generation['level'] %2) != 0) {
#				 		$rlines[ $i_r ] = array($posX+$crX, $posY+$crY);
				 		$rlines[ $i_r ] = array($posX, $posY);
						$i_r++;
					}
				}
				// Ende Person ------------------------------------------------------------------


				// Y-Position fuer nächste 'Generation' (eins nach unten) verschieben
				if((($xml_generation['level'] %2) == 0) && ($xml_generations['type'] = 'd'))
					// Bei "Partnern" nur Boxhoehe + Abstand
					$posY = $posY+$size['margin']['box']['y']+$size['box']['max_height'];
				else
					// Bei "Kindern" zusaetzlich Versatz fuer Elternbeziehungsdatum + Verwandschaftslinien
					$posY = $posY+$size['margin']['box']['y']+$height_reldate+$size['margin']['box']['y']+$size['box']['max_height']+$size['margin']['box']['y']+($vl*2);

    		unset($gen_divorced);
			}
			//------------------------------------------------------------------
			// Ende Generation
			//------------------------------------------------------------------

	/*
echo '<pre>';
print_r($width);
print_r($hlines);
print_r($vlines);
print_r($rlines);
echo '</pre>';
exit;
	*/

			//------------------------------------------------------------------
			// waagrechte Verwandschaftslinien zeichnen
			//------------------------------------------------------------------
			$pdf->SetLineStyle(array('width' => $size['line']['blood'], 'cap' => 'round', 'join' => 'round', 'dash' => LINETYPE_BLOOD, 'color' => array(LINECOLOR_BLOOD)));
			if(count($hlines)!=0) {
				for($i=0;$i<count($hlines);$i++) {
					$hlines[$i] = $pdf->Line($hlines[$i][1], $hlines[$i][2], $hlines[$i][3], $hlines[$i][4]);
				}
			}

			
// DEBUG
if($_GET['debug'] == "all" || $_GET['debug'] == "page") {
#	$pdf->SetXY(1,1);
#	$pdf->SetLineStyle(array('width' => $size['line']['title'], 'cap' => LINESYTYLE_CAP, 'join' => LINESYTYLE_JOIN, 'dash' => LINETYPE_TITLES, 'color' => array(255,0,0)));
#	$pdf->Cell($page_width-2, $page_height-32, '', 1, 1, L); // Rahmengroesse, Inhalt, Rahmenart, Ausrichtung
			
	$pdf->SetXY($size['margin']['left'],$size['margin']['top']);
	$pdf->SetLineStyle(array('width' => $size['line']['title'], 'cap' => LINESYTYLE_CAP, 'join' => LINESYTYLE_JOIN, 'dash' => LINETYPE_TITLES, 'color' => array(255,0,255)));
	$pdf->Cell($page_width-$size['margin']['left']-$size['margin']['right'], $page_height-$size['margin']['top']-$size['margin']['bottom'], '', 1, 1, L); // Rahmengroesse, Inhalt, Rahmenart, Ausrichtung
}			

			//------------------------------------------------------------------
			// PDF ausgeben
			//------------------------------------------------------------------
			if($_GET['save'])
				$pdf->Output(date("Y-m-d H-i").'_xml2pdf_'.substr($xml_src, 4,-4).'.pdf', 'D'); // Direktdownload
			else
				$pdf->Output(); // Im Browser anzeigen
#				$pdf->Output(date("Y-m-d h-i").'_xml2pdf_'.substr($xml_src, 4,-4).'.pdf', 'I'); // Mit Dateinamen im Browser anzeigen 

		}



	}else{
		// Wenn Probleme beim Lesen des XMLs
		exit('Couldn\'t read XML-File.');
	}


?>