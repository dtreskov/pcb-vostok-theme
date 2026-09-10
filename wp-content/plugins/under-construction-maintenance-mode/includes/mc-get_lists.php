<?php
// $apiKey = $_POST['apiKey'];
// $dataCenter = substr( $apiKey , strpos( $apiKey,'-' ) + 1 );
// $url = 'https://'. $dataCenter. '.api.mailchimp.com/3.0/lists/';

// // $json = json_encode( $data );

// $ch = curl_init( $url );

// curl_setopt( $ch, CURLOPT_USERPWD, 'user:' . $apiKey );
// curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );
// curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
// curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
// curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
// curl_setopt( $ch, CURLOPT_POST, true );
// curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
// // curl_setopt( $ch, CURLOPT_POSTFIELDS, $json );

// curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; .NET CLR 1.0.3705; .NET CLR 1.1.4322)' );

// $result = curl_exec( $ch );

// $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
// curl_close( $ch );

// // return $httpCode;
// $json_decode = json_decode($result) ;
// $count = 0;
// foreach ( $json_decode as $key => $val ) :
//   if ($count == 0) :
//     foreach ($val as $array ) :
//       echo '<option value="'.$array->id.'">'.$array->id.'</option>';
//     endforeach;
//   endif;
//   $count ++;
// endforeach;
?>
