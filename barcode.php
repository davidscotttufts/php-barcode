<?php

/*
 *  Author:  David S. Tufts
 *  Company: Rocketwood.LLC
 *	  www.rocketwood.com
 *  Date:	05/25/2003
 *  Usage:
 *	  <img src="/barcode.php?text=testing" alt="testing" />
 *
 * Modified by: Artur Gołdyn
 * Company: RichApps Artur Gołdyn
 *   www.richapps.pl
 * Date: 01.03.2016
 */

require_once("PhpBarcode.php");

// Get pararameters that are passed in through $_GET or set to the default value
$text = (isset($_GET["text"])?$_GET["text"]:"0");
$size = (isset($_GET["size"])?$_GET["size"]:"20");
$orientation = (isset($_GET["orientation"])?$_GET["orientation"]:"horizontal");
$code_type = (isset($_GET["codetype"])?$_GET["codetype"]:"code128");

$image = PhpBarcode::GenerateBarcode($code_type, $text, $orientation, $size);

PhpBarcode::DrawBarcodeToScreen($image);

//$pngData = PhpBarcode::GetPngData($image);
//header('Content-type: image/png');
//echo $pngData;