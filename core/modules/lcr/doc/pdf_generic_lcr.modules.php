<?php

dol_include_once('/core/modules/facture/modules_facture.php');

class pdf_generic_lcr extends ModelePDFFactures {
	
    var $db;
    var $name;
    var $description;
    var $type;

    var $phpmin = array(4,3,0); // Minimum version of PHP required by module
    var $version = 'dolibarr';

    var $page_largeur;
    var $page_hauteur;
    var $format;
	var $marge_gauche;
	var	$marge_droite;
	var	$marge_haute;
	var	$marge_basse;

	var $emetteur;	// Objet societe qui emet

	function __construct($db)
	{
		global $conf,$langs,$mysoc;

		$langs->load("main");
		$langs->load("bills");

		$this->db = $db;
		$this->name = "Facture Epoxy 3000";
		$this->description = $langs->trans('PDFCrabeDescription');

		// Dimension page pour format A4
		$this->type = 'pdf';
		$formatarray=pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur,$this->page_hauteur);
		$this->marge_gauche=isset($conf->global->MAIN_PDF_MARGIN_LEFT)?$conf->global->MAIN_PDF_MARGIN_LEFT:10;
		$this->marge_droite=isset($conf->global->MAIN_PDF_MARGIN_RIGHT)?$conf->global->MAIN_PDF_MARGIN_RIGHT:10;
		$this->marge_haute =isset($conf->global->MAIN_PDF_MARGIN_TOP)?$conf->global->MAIN_PDF_MARGIN_TOP:10;
		$this->marge_basse =isset($conf->global->MAIN_PDF_MARGIN_BOTTOM)?$conf->global->MAIN_PDF_MARGIN_BOTTOM:10;

		$this->option_logo = 1;                    // Affiche logo
		$this->option_tva = 1;                     // Gere option tva FACTURE_TVAOPTION
		$this->option_modereg = 1;                 // Affiche mode reglement
		$this->option_condreg = 1;                 // Affiche conditions reglement
		$this->option_codeproduitservice = 1;      // Affiche code produit-service
		$this->option_multilang = 1;               // Dispo en plusieurs langues
		$this->option_escompte = 1;                // Affiche si il y a eu escompte
		$this->option_credit_note = 1;             // Support credit notes
		$this->option_freetext = 1;				   // Support add of a personalised text
		$this->option_draft_watermark = 1;		   // Support add of a watermark on drafts

		$this->franchise=!$mysoc->tva_assuj;

		// Get source company
		$this->emetteur=$mysoc;
		if (empty($this->emetteur->country_code)) $this->emetteur->country_code=substr($langs->defaultlang,-2);    // By default, if was not defined

		// Define position of columns
		$this->posxdesc=$this->marge_gauche+1;
		$this->posxtva=112;
		$this->posxup=126;
		$this->posxqty=145;
		$this->posxdiscount=162;
		$this->postotalht=174;
		if (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT)) $this->posxtva=$this->posxup;
		$this->posxpicture=$this->posxtva - (empty($conf->global->MAIN_DOCUMENTS_WITH_PICTURE_WIDTH)?20:$conf->global->MAIN_DOCUMENTS_WITH_PICTURE_WIDTH);	// width of images
		if ($this->page_largeur < 210) // To work with US executive format
		{
			$this->posxpicture-=20;
			$this->posxtva-=20;
			$this->posxup-=20;
			$this->posxqty-=20;
			$this->posxdiscount-=20;
			$this->postotalht-=20;
		}

		$this->tva=array();
		$this->localtax1=array();
		$this->localtax2=array();
		$this->atleastoneratenotnull=0;
		$this->atleastonediscount=0;
	}



	function write_file($object,$outputlangs,$srctemplatepath='',$hidedetails=0,$hidedesc=0,$hideref=0, &$TtoGenerate)
	{
		global $user,$langs,$conf,$mysoc,$db,$hookmanager;

		if (! is_object($outputlangs)) $outputlangs=$langs;
		// For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
		if (! empty($conf->global->MAIN_USE_FPDF)) $outputlangs->charset_output='ISO-8859-1';

		$outputlangs->load("main");
		$outputlangs->load("dict");
		$outputlangs->load("companies");
		$outputlangs->load("bills");
		$outputlangs->load("products");

		if ($conf->facture->dir_output)
		{
			
			
			$dir = $conf->lcr->dir_output . "/";
			$file = $dir . "" . 'lcr_'.date('YmdHis') . ".pdf";
			
			if (! file_exists($dir))
			{
				if (dol_mkdir($dir) < 0)
				{
					$this->error=$langs->transnoentities("ErrorCanNotCreateDir",$dir);
					return 0;
				}
			}

			if (file_exists($dir))
			{
				$nblignes = count($object->lines);

                $pdf=pdf_getInstance($this->format);
                $default_font_size = pdf_getPDFFontSize($outputlangs);	// Must be after pdf_getInstance
				$heightforinfotot = 50;	// Height reserved to output the info and total part
		        $heightforfreetext= (isset($conf->global->MAIN_PDF_FREETEXT_HEIGHT)?$conf->global->MAIN_PDF_FREETEXT_HEIGHT:5);	// Height reserved to output the free text on last page
	            $heightforfooter = $this->marge_basse + 8;	// Height reserved to output the footer (value include bottom margin)
                $pdf->SetAutoPageBreak(1,0);

				$this->_showLCR($pdf, $object, $outputlangs, $TtoGenerate);

				$pdf->Close();

				$pdf->Output($file,'F');

				// Add pdfgeneration hook
				if (! is_object($hookmanager))
				{
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager=new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters=array('file'=>$file,'object'=>$object,'outputlangs'=>$outputlangs);
				global $action;
				$reshook=$hookmanager->executeHooks('afterPDFCreation',$parameters,$this,$action);    // Note that $action and $object may have been modified by some hooks

				if (! empty($conf->global->MAIN_UMASK))
				@chmod($file, octdec($conf->global->MAIN_UMASK));

				return 1;   // Pas d'erreur
			}
			else
			{
				$this->error=$langs->trans("ErrorCanNotCreateDir",$dir);
				return 0;
			}
		}
		else
		{
			$this->error=$langs->trans("ErrorConstantNotDefined","FAC_OUTPUTDIR");
			return 0;
		}
		$this->error=$langs->trans("ErrorUnknown");
		return 0;   // Erreur par defaut
	}

	function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		global $conf,$langs;

		$outputlangs->load("main");
		$outputlangs->load("bills");
		$outputlangs->load("propal");
		$outputlangs->load("companies");

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf,$outputlangs,$this->page_hauteur);

		// Show Draft Watermark
		if($object->statut==0 && (! empty($conf->global->FACTURE_DRAFT_WATERMARK)) )
        {
		      pdf_watermark($pdf,$outputlangs,$this->page_hauteur,$this->page_largeur,'mm',$conf->global->FACTURE_DRAFT_WATERMARK);
        }

		$pdf->SetTextColor(0,0,60);
		$pdf->SetFont('','B', $default_font_size + 3);

		$posy=$this->marge_haute;
        $posx=$this->page_largeur-$this->marge_droite-100;

		$pdf->SetXY($this->marge_gauche,$posy);

		// Logo
		$logo=$conf->mycompany->dir_output.'/logos/'.$this->emetteur->logo;
		if ($this->emetteur->logo)
		{
			if (is_readable($logo))
			{
			    $height=pdf_getHeightForLogo($logo);
				$pdf->Image($logo, $this->marge_gauche, $posy, 0, $height);	// width=0 (auto)
			}
			else
			{
				$pdf->SetTextColor(200,0,0);
				$pdf->SetFont('','B',$default_font_size - 2);
				$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorLogoFileNotFound",$logo), 0, 'L');
				$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorGoToGlobalSetup"), 0, 'L');
			}
		}
		else
		{
			$text=$this->emetteur->name;
			$pdf->MultiCell(100, 4, $outputlangs->convToOutputCharset($text), 0, 'L');
		}

		$pdf->SetFont('','B', $default_font_size + 3);
		$pdf->SetXY($posx,$posy);
		$pdf->SetTextColor(0,0,60);
		$title=$outputlangs->transnoentities("Invoice");
		if ($object->type == 1) $title=$outputlangs->transnoentities("InvoiceReplacement");
		if ($object->type == 2) $title=$outputlangs->transnoentities("InvoiceAvoir");
		if ($object->type == 3) $title=$outputlangs->transnoentities("InvoiceDeposit");
		if ($object->type == 4) $title=$outputlangs->transnoentities("InvoiceProFormat");
		$pdf->MultiCell(100, 3, $title, '', 'R');

		$pdf->SetFont('','B',$default_font_size);

		$posy+=5;
		$pdf->SetXY($posx,$posy);
		$pdf->SetTextColor(0,0,60);
		$pdf->MultiCell(100, 4, $outputlangs->transnoentities("Ref")." : " . $outputlangs->convToOutputCharset($object->ref), '', 'R');

		$posy+=1;
		$pdf->SetFont('','', $default_font_size - 2);

		if ($object->ref_client)
		{
			$posy+=4;
			$pdf->SetXY($posx,$posy);
			$pdf->SetTextColor(0,0,60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("RefCustomer")." : " . $outputlangs->convToOutputCharset($object->ref_client), '', 'R');
		}

		if ($object->type == 0 && $objectidnext)
		{
			$objectreplacing=new Facture($this->db);
			$objectreplacing->fetch($objectidnext);

			$posy+=3;
			$pdf->SetXY($posx,$posy);
			$pdf->SetTextColor(0,0,60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ReplacementByInvoice").' : '.$outputlangs->convToOutputCharset($objectreplacing->ref), '', 'R');
		}
		if ($object->type == 1)
		{
			$objectreplaced=new Facture($this->db);
			$objectreplaced->fetch($object->fk_facture_source);

			$posy+=4;
			$pdf->SetXY($posx,$posy);
			$pdf->SetTextColor(0,0,60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ReplacementInvoice").' : '.$outputlangs->convToOutputCharset($objectreplaced->ref), '', 'R');
		}
		if ($object->type == 2)
		{
			$objectreplaced=new Facture($this->db);
			$objectreplaced->fetch($object->fk_facture_source);

			$posy+=3;
			$pdf->SetXY($posx,$posy);
			$pdf->SetTextColor(0,0,60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("CorrectionInvoice").' : '.$outputlangs->convToOutputCharset($objectreplaced->ref), '', 'R');
		}

		$posy+=4;
		$pdf->SetXY($posx,$posy);
		$pdf->SetTextColor(0,0,60);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities("DateInvoice")." : " . dol_print_date($object->date,"day",false,$outputlangs), '', 'R');

		if ($object->type != 2)
		{
			$posy+=3;
			$pdf->SetXY($posx,$posy);
			$pdf->SetTextColor(0,0,60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("DateEcheance")." : " . dol_print_date($object->date_lim_reglement,"day",false,$outputlangs,true), '', 'R');
		}

		if ($object->client->code_client)
		{
			$posy+=3;
			$pdf->SetXY($posx,$posy);
			$pdf->SetTextColor(0,0,60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("CustomerCode")." : " . $outputlangs->transnoentities($object->client->code_client), '', 'R');
		}

		$posy+=1;

		if ($showaddress)
		{

			// Show sender
			$posy=40;
			$posx=$this->marge_gauche;
			if (! empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT)) $posx=$this->page_largeur-$this->marge_droite-80;
			$hautcadre=38;

			// Show sender frame
			$pdf->SetTextColor(0,0,0);
			$pdf->SetFont('','', $default_font_size - 2);
			$pdf->SetXY($posx,$posy-5);
			$pdf->MultiCell(66,5, $outputlangs->transnoentities("BillFrom").":", 0, 'L');
			$pdf->SetXY($posx,$posy);
			$pdf->SetFillColor(230,230,230);
			$pdf->MultiCell(92, $hautcadre, "", 0, 'R', 1);
			$pdf->SetTextColor(0,0,60);

			// Show sender name
			$pdf->SetXY($posx+2,$posy+3);
			$pdf->SetFont('','B', $default_font_size);
			$pdf->MultiCell(90, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
			$posy=$pdf->getY();

			// Show sender information
			$pdf->SetXY($posx+2,$posy);
			$pdf->SetFont('','', $default_font_size - 1);
			$pdf->MultiCell(90, 4, $carac_emetteur, 0, 'L');



			// If BILLING contact defined on invoice, we use it
			$usecontact=false;

			// Recipient name
			if (! empty($usecontact))
			{
				// On peut utiliser le nom de la societe du contact
				if (! empty($conf->global->MAIN_USE_COMPANY_NAME_OF_CONTACT)) $socname = $object->contact->socname;
				else $socname = $object->client->nom;
				$carac_client_name=$outputlangs->convToOutputCharset($socname);
			}
			else
			{
				$carac_client_name=$outputlangs->convToOutputCharset($object->client->nom);
			}

			$carac_client=pdf_build_address($outputlangs,$this->emetteur,$object->client,($usecontact?$object->contact:''),$usecontact,'target');

			// Show recipient
			$widthrecbox=92;
			if ($this->page_largeur < 210) $widthrecbox=84;	// To work with US executive format
			$posy=40;
			$posx=$this->page_largeur-$this->marge_droite-$widthrecbox;
			if (! empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT)) $posx=$this->marge_gauche;

			// Show recipient frame
			$pdf->SetTextColor(0,0,0);
			$pdf->SetFont('','', $default_font_size - 2);
			$pdf->SetXY($posx+2,$posy-5);
			$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("BillTo").":",0,'L');
			$pdf->Rect($posx, $posy, $widthrecbox, $hautcadre);

			// Show recipient name
			$pdf->SetXY($posx+2,$posy+3);
			$pdf->SetFont('','B', $default_font_size);
			$pdf->MultiCell($widthrecbox, 4, $carac_client_name, 0, 'L');

			// Show recipient information
			$pdf->SetFont('','', $default_font_size - 1);
			$pdf->SetXY($posx+2,$posy+4+(dol_nboflines_bis($carac_client_name,50)*4));
			$pdf->MultiCell($widthrecbox, 4, $carac_client, 0, 'L');
		}

		$pdf->SetTextColor(0,0,0);
	}

	function _showLCR($pdf, $object, $outputlangs, &$TtoGenerate)
	{
		global $db, $conf;
		
		//Gestion LCR /////////////////////////////////////////////////////////////////////
	   	
		$pdf->AddPage();
		$posy =50;
		$pdf->SetDrawColor(0,0,0);
	
		$default_font_size = pdf_getPDFFontSize($outputlangs);
		
		foreach($TtoGenerate as $ii => $ref_piece) {
			
			$f = new Facture($db);
			$f->fetch('', $ref_piece);
			$f->fetch_thirdparty();
			$object = &$f;
			
			$curx=$this->marge_gauche;
			$cury=$posy-30;			   
			$pdf->SetFont('','', $default_font_size - 1);
			$pdf->writeHTMLCell(53, 20, 10, $cury-8, $outputlangs->convToOutputCharset('MERCI DE NOUS RETOURNER LA PRESENTE TRAITE SOUS 8 JOURS.'), 0, 1, false, true, 'J',true);
			
			$pdf->SetFont('','', $default_font_size - 3);
			$pdf->writeHTMLCell(40, 20, 70, $cury-8, $outputlangs->convToOutputCharset('Contre cette LETTRE DE CHANGE STIPULEE SANS FRAIS'), 0, 1, false, true, 'J',true);
			$pdf->writeHTMLCell(40, 20, 70, $cury-3, $outputlangs->convToOutputCharset('Veuillez payer la somme indiquée ci_dessous à l\'ordre de'), 0, 1, false, true, 'J',true);
			
			$pdf->SetFont('','', $default_font_size - 2);
			$pdf->writeHTMLCell(20, 20, 115, $cury-8, $outputlangs->convToOutputCharset($conf->global->MAIN_INFO_SOCIETE_NOM), 0, 1, false, true, 'J',true);
			$pdf->writeHTMLCell(40, 20, 115, $cury-5, $outputlangs->convToOutputCharset($conf->global->MAIN_INFO_SOCIETE_ADDRESS), 0, 1, false, true, 'J',true);
			$pdf->writeHTMLCell(40, 20, 115, $cury+1, $outputlangs->convToOutputCharset($conf->global->MAIN_INFO_SOCIETE_ZIP.' '.$conf->global->MAIN_INFO_SOCIETE_TOWN), 0, 1, false, true, 'J',true);
			
			
			$pdf->writeHTMLCell(150, 20, 10, $cury+1, $outputlangs->convToOutputCharset('A '.$conf->global->MAIN_INFO_SOCIETE_TOWN.', le'), 0, 1, false, true, 'J',true);
			

			//Affichage code monnaie 
			$pdf->SetXY(180, $cury+1);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',7);
			$pdf->Cell(18, 0, "Code Monnaie",0,1,C);
			$pdf->SetXY(180, $cury+5);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',14);
			$pdf->Cell(18, 0, $outputlangs->trans($conf->currency),0,0,C);

			//Affichage lieu / date
			$cury+=5;
			$pdf->SetXY(15, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',8);
			$pdf->Cell(2, 0, "A",0,1,C);
			$pdf->SetXY(20, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
			$pdf->Cell(15, 0, $outputlangs->convToOutputCharset($this->emetteur->ville),0,1,C);
			$pdf->SetXY(40, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',8);
			$pdf->Cell(2, 0, ", le",0,1,C);

			
			// jolie fl�che ...
			$curx=43;
			$cury+=2;
			$largeur_cadre=5;
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre+5, $cury);
			$pdf->Line($curx+$largeur_cadre+5, $cury, $curx+$largeur_cadre+5, $cury+2);
			$pdf->Line($curx+$largeur_cadre+4, $cury+2, $curx+$largeur_cadre+6, $cury+2);
			$pdf->Line($curx+$largeur_cadre+4, $cury+2, $curx+$largeur_cadre+5, $cury+3);
			$pdf->Line($curx+$largeur_cadre+6, $cury+2, $curx+$largeur_cadre+5, $cury+3);
			// fin jolie fl�che

			//Affichage toute la ligne qui commence par "montant pour contr�le" ...
			$curx=$this->marge_gauche;
			$cury+=5;
			$hauteur_cadre=8;
			$largeur_cadre=27;
			$pdf->SetXY($curx, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',7);
			$pdf->Cell($largeur_cadre, 0, "Montant pour contrôle",0,0,C);
			$pdf->Line($curx, $cury, $curx, $cury+$hauteur_cadre);
			$pdf->Line($curx, $cury+$hauteur_cadre, $curx+$largeur_cadre, $cury+$hauteur_cadre);
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre, $cury+$hauteur_cadre);
			$pdf->SetXY($curx, $cury+4);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
			$pdf->Cell($largeur_cadre, 0, price($object->total_ttc),0,0,C);
					
			$curx=$curx+$largeur_cadre+5;
			$hauteur_cadre=8;
			$largeur_cadre=25;
			$pdf->SetXY($curx, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',7);
			$pdf->Cell($largeur_cadre, 0, "Date de création",0,0,C);
			$pdf->Line($curx, $cury, $curx, $cury+$hauteur_cadre);
			$pdf->Line($curx, $cury+$hauteur_cadre, $curx+$largeur_cadre, $cury+$hauteur_cadre);
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre, $cury+$hauteur_cadre);
			$pdf->SetXY($curx, $cury+4);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
			$pdf->Cell($largeur_cadre, 0, dol_print_date($object->date,"day",false,$outpulangs),0,0,C);

			$curx=$curx+$largeur_cadre+5;
			$hauteur_cadre=8;
			$largeur_cadre=25;
			$pdf->SetXY($curx, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',7);
			$pdf->Cell($largeur_cadre, 0, "Echéance",0,0,C);
			$pdf->Line($curx, $cury, $curx, $cury+$hauteur_cadre);
			$pdf->Line($curx, $cury+$hauteur_cadre, $curx+$largeur_cadre, $cury+$hauteur_cadre);
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre, $cury+$hauteur_cadre);
			$pdf->SetXY($curx, $cury+4);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
			$pdf->Cell($largeur_cadre, 0, dol_print_date($object->date_lim_reglement,"day"),0,0,C);

			$curx=$curx+$largeur_cadre+5;
			$hauteur_cadre=8;
			$largeur_cadre=75;
			$pdf->SetXY($curx, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',7);
			$pdf->Cell($largeur_cadre, 0, "LCR Seulement",0,0,C);
			
			$largeurportioncadre=30;
			$pdf->Line($curx, $cury, $curx, $cury+$hauteur_cadre);
			$pdf->Line($curx, $cury+$hauteur_cadre, $curx+$largeurportioncadre, $cury+$hauteur_cadre);
			$curx+=$largeurportioncadre;
			$pdf->Line($curx, $cury+2, $curx, $cury+$hauteur_cadre);

			$curx+=10;
			$largeurportioncadre=6;
			$pdf->Line($curx, $cury+2, $curx, $cury+$hauteur_cadre);
			$pdf->Line($curx, $cury+$hauteur_cadre, $curx+$largeurportioncadre, $cury+$hauteur_cadre);
			$curx+=$largeurportioncadre;
			$pdf->Line($curx, $cury+2, $curx, $cury+$hauteur_cadre);

			$curx+=3;
			$largeurportioncadre=6;
			$pdf->Line($curx, $cury+2, $curx, $cury+$hauteur_cadre);
			$pdf->Line($curx, $cury+$hauteur_cadre, $curx+$largeurportioncadre, $cury+$hauteur_cadre);
			$curx+=$largeurportioncadre;
			$pdf->Line($curx, $cury+2, $curx, $cury+$hauteur_cadre);

			$curx+=3;
			$largeurportioncadre=12;
			$pdf->Line($curx, $cury+2, $curx, $cury+$hauteur_cadre);
			$pdf->Line($curx, $cury+$hauteur_cadre, $curx+$largeurportioncadre, $cury+$hauteur_cadre);
			$curx+=$largeurportioncadre;
			$pdf->Line($curx, $cury, $curx, $cury+$hauteur_cadre);

			$curx+=3;
			$hauteur_cadre=8;
			$largeur_cadre=30;
			$pdf->SetXY($curx, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',7);
			$pdf->Cell($largeur_cadre, 0, "Montant",0,0,C);
			$pdf->Line($curx, $cury, $curx, $cury+$hauteur_cadre);
			$pdf->Line($curx, $cury+$hauteur_cadre, $curx+$largeur_cadre, $cury+$hauteur_cadre);
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre, $cury+$hauteur_cadre);
			$pdf->SetXY($curx, $cury+4);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
			$pdf->Cell($largeur_cadre, 0, price($object->total_ttc),0,0,C);

			$cury=$cury+$hauteur_cadre+3;
			$curx=20;
			$hauteur_cadre=6;
			$largeur_cadre=70;
			$pdf->Line($curx, $cury, $curx, $cury+$hauteur_cadre);
			$pdf->Line($curx, $cury, $curx+$largeur_cadre/5, $cury);
			$pdf->Line($curx, $cury+$hauteur_cadre, $curx+$largeur_cadre/5, $cury+$hauteur_cadre);
			
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre, $cury+$hauteur_cadre);
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre*4/5, $cury);
			$pdf->Line($curx+$largeur_cadre, $cury+$hauteur_cadre, $curx+$largeur_cadre*4/5, $cury+$hauteur_cadre);
			$pdf->SetXY($curx, $cury+2);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
			$pdf->Cell($largeur_cadre, 1, $outputlangs->convToOutputCharset($object->ref),0,0,C);

			$curx=$curx+$largeur_cadre+15;
			$largeur_cadre=50;
			$pdf->Line($curx, $cury, $curx, $cury+$hauteur_cadre);
			$pdf->Line($curx, $cury, $curx+$largeur_cadre/5, $cury);
			$pdf->Line($curx, $cury+$hauteur_cadre, $curx+$largeur_cadre/5, $cury+$hauteur_cadre);
			
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre, $cury+$hauteur_cadre);
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre*4/5, $cury);
			$pdf->Line($curx+$largeur_cadre, $cury+$hauteur_cadre, $curx+$largeur_cadre*4/5, $cury+$hauteur_cadre);
			$pdf->SetXY($curx, $cury+2);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
// MB leave blank
			//$pdf->Cell($largeur_cadre, 0, "Réf ",0,0,C);

			$curx=$curx+$largeur_cadre+10;
			$largeur_cadre=30;
			$pdf->Line($curx, $cury, $curx, $cury+$hauteur_cadre);
			$pdf->Line($curx, $cury, $curx+$largeur_cadre/5, $cury);
			$pdf->Line($curx, $cury+$hauteur_cadre, $curx+$largeur_cadre/5, $cury+$hauteur_cadre);
			
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre, $cury+$hauteur_cadre);
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre*4/5, $cury);
			$pdf->Line($curx+$largeur_cadre, $cury+$hauteur_cadre, $curx+$largeur_cadre*4/5, $cury+$hauteur_cadre);
			$pdf->SetXY($curx, $cury+2);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
// MB leave blank
			//$pdf->Cell($largeur_cadre, 0, "R�f ",0,0,C);
 
				// RIB client
			$cury=$cury+$hauteur_cadre+3;
			$largeur_cadre=70;
			$hauteur_cadre=6;
			$sql = "SELECT rib.fk_soc, rib.domiciliation, rib.code_banque, rib.code_guichet, rib.number, rib.cle_rib";
			$sql.= " FROM ".MAIN_DB_PREFIX ."societe_rib as rib";
			$sql.= " WHERE rib.fk_soc = ".$object->client->id;
			$resql=$this->db->query($sql);
			if ($resql)
			{
				$num = $this->db->num_rows($resql);
				$i=0;
				while ($i <= $num)
				{
					$cpt = $this->db->fetch_object($resql);

					$curx=$this->marge_gauche;
					$pdf->Line($curx, $cury, $curx+$largeur_cadre, $cury);
					$pdf->Line($curx, $cury, $curx, $cury+$hauteur_cadre);
					$pdf->Line($curx+22, $cury, $curx+22, $cury+$hauteur_cadre-2);
					$pdf->Line($curx+35, $cury, $curx+35, $cury+$hauteur_cadre-2);
					$pdf->Line($curx+60, $cury, $curx+60, $cury+$hauteur_cadre-2);
					$pdf->Line($curx+70, $cury, $curx+70, $cury+$hauteur_cadre);
					$pdf->SetXY($curx+5, $cury+$hauteur_cadre-4);
					$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
					if ($cpt->code_banque && $cpt->code_guichet && $cpt->number && $cpt->cle_rib)
						$pdf->Cell($largeur_cadre, 1, $cpt->code_banque."             ".$cpt->code_guichet."         ".$cpt->number."        ".$cpt->cle_rib,0,0,L);
					$pdf->SetXY($curx, $cury+$hauteur_cadre-1);
					$pdf->SetFont(pdf_getPDFFont($outputlangs),'',6);
					$pdf->Cell($largeur_cadre, 1, "Code établissement    Code guichet           N° de compte            Cl RIB",0,0,L);
					$curx=150;				
					$largeur_cadre=55;
					$pdf->SetXY($curx, $cury);
					$pdf->SetFont(pdf_getPDFFont($outputlangs),'',6);
					$pdf->Cell($largeur_cadre, 1, "Domiciliation bancaire",0,0,C);
					$pdf->SetXY($curx, $cury+2);
					$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
					if ($cpt->domiciliation)
						$pdf->Cell($largeur_cadre, 5,$outputlangs->convToOutputCharset($cpt->domiciliation) ,1,0,C);
					$i++;
				}
			}
//				
			$cury=$cury+$hauteur_cadre+3;
			$curx=$this->marge_gauche;
			$largeur_cadre=20;
			$pdf->SetXY($curx, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',6);
			$pdf->Cell($largeur_cadre, 1, "Acceptation ou aval",0,0,L);
			// jolie fl�che ...
			$cury += 2;
			$pdf->Line($curx+$largeur_cadre, $cury, $curx+$largeur_cadre+5, $cury);
			$pdf->Line($curx+$largeur_cadre+5, $cury, $curx+$largeur_cadre+5, $cury+2);
			$pdf->Line($curx+$largeur_cadre+4, $cury+2, $curx+$largeur_cadre+6, $cury+2);
			$pdf->Line($curx+$largeur_cadre+4, $cury+2, $curx+$largeur_cadre+5, $cury+3);
			$pdf->Line($curx+$largeur_cadre+6, $cury+2, $curx+$largeur_cadre+5, $cury+3);
			// fin jolie fl�che

			//Coordonn�es du tir�
			$curx+=50;
			$largeur_cadre=20;
			$hauteur_cadre=6;
			$pdf->SetXY($curx, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',6);
			$pdf->MultiCell($largeur_cadre, $hauteur_cadre, "Nom \n et Adresse \n du tiré",0,R);
			$pdf->SetXY($curx+$largeur_cadre+2, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
			$arrayidcontact = $object->getIdContact('external','BILLING');
			$carac_client=$outputlangs->convToOutputCharset($object->client->nom);
			$carac_client.="\n".$outputlangs->convToOutputCharset($object->client->adresse);
			$carac_client.="\n".$outputlangs->convToOutputCharset($object->client->cp) . " " . $outputlangs->convToOutputCharset($object->client->ville)."\n";
			$pdf->MultiCell($largeur_cadre*2.5, $hauteur_cadre, $carac_client,1,C);
			//N� Siren
			$pdf->SetXY($curx, $cury+16);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',6);
			$pdf->MultiCell($largeur_cadre, 4, "N° SIREN du tiré",0,R);
			$pdf->SetXY($curx+$largeur_cadre+2, $cury+16);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'B',8);
			$pdf->MultiCell($largeur_cadre*2.5, 4, $outputlangs->convToOutputCharset(empty($object->client->siren) ? $object->client->idprof1 : $object->client->siren),1,C);
			//signature du tireur
			$pdf->SetXY($curx+$largeur_cadre*5, $cury);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',6);
			$pdf->MultiCell($largeur_cadre*2, 4, "Signature du tireur",0,C);

			$pdf->Line(0,$cury+40,$this->page_largeur, $cury+40);		
			$pdf->SetXY($curx+100, $cury+36);
			$pdf->SetFont(pdf_getPDFFont($outputlangs),'',6);
			$pdf->MultiCell(50, 4, "Ne rien inscrire au dessous de cette ligne",0,R);
		
			$posy+=96;
			
			$ii++;
			
			
		}

//fin mb ///////////
	}
	
}
