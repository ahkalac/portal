<?php
session_start();
include("config.php");

// Zaten giriş yapmış bir kullanıcı varsa yönlendir
if (isset($_SESSION['id'])) {
    header('Location: index.php');
    exit; // Kodun devam etmemesi için çıkış yapılıyor
}

// Form gönderilmiş mi kontrol et
if (isset($_POST['login'])) {
    // Kullanıcı adı ve şifreyi al
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    // Kullanıcıyı veritabanından sorgula
    $sql = "SELECT * FROM [PORTAL3].[dbo].[users] WHERE email = ? OR username = ?";
    $params = array($email, $email);
    $query = sqlsrv_query($conn, $sql, $params);
    
    // Sonuç kontrolü
    if ($query && sqlsrv_has_rows($query)) {
        // Kullanıcı bulundu, şifreyi doğrula
        $row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC);
        
        if (password_verify($password, $row['password'])) {
            // Kullanıcının durumunu kontrol et
            if ($row['status'] == 0) {
                $answer = "Kullanıcı hesabınız henüz aktif edilmemiştir.";
            } else {
                // Tarih verisini kontrol et
                if ($row['history'] === NULL) {
                    // Eğer tarih NULL ise, geçerli tarihe göre işlem yap
                    $historyTimestamp = time(); // Şu anki zaman damgası
                } else {
                    // Tarih verisini Unix zaman damgasına dönüştür
                    $historyStr = $row['history']->format('Y-m-d'); // Tarih ve saat stringi
                    $historyTimestamp = strtotime($historyStr);
                }
                
                // Şu anki Unix zaman damgasını al
                $currentTimestamp = time();
            
                // 2 gün önce giriş yapmış mı?
                if (($currentTimestamp - $historyTimestamp <= 172800)) {
                    // Oturum bilgilerini sakla
                    $_SESSION['id'] = $row['id'];
                    
					// Kullanıcı giriş yaptıktan sonra 'history' tarihini güncelle
					$history = date("Y-m-d H:i:s");
					$sqlUpdateHistory = "UPDATE [PORTAL3].[dbo].[users] SET history = ? WHERE id = ?";
					$paramsUpdateHistory = array($history, $_SESSION['id']);
					$resultUpdateHistory = sqlsrv_query($conn, $sqlUpdateHistory, $paramsUpdateHistory);
					
                    // Kullanıcı uygun durumda, doğrudan 'index.php'ye yönlendir
                    header("Location: index.php");
                    exit;
                } else {
                    // OTP kodu üret
                    $otpCode = rand(100000, 999999); // 6 haneli OTP kodu
                    $otpExpiry = date("Y-m-d H:i:s", strtotime('+2 minutes')); // OTP'nin geçerlilik süresi

                    // OTP kodunu ve geçerlilik süresini veritabanına kaydet
                    $sqlOtp = "INSERT INTO [PORTAL3].[dbo].[otp_codes] (user_id, otp_code, expiry_time) VALUES (?, ?, ?)";
                    $paramsOtp = array($row['id'], $otpCode, $otpExpiry);
                    $resultOtp = sqlsrv_query($conn, $sqlOtp, $paramsOtp);

                    // SMS gönderimi (bu kısımda SMS gönderme fonksiyonunu kullanabilirsiniz)
                    $phoneNumber = $row['telephone']; // Telefon numarası
                    $message = "$otpCode dogrulama kodunuz, $otpExpiry tarihine kadar geçerlidir.";
                    sendSms($phoneNumber, $message);

                    // OTP kodunu oturumda sakla
                    $_SESSION['otp_id'] = $row['id'];

                    // Kullanıcıyı OTP doğrulama sayfasına yönlendir
                    header("Location: otp_verification.php");
                    exit;
                }
            }
        } else {
            // Şifre yanlışsa hata mesajı göster
            $answer = "Şifre yanlış. Tekrar deneyiniz.";
        }
    } else {
        // Kullanıcı bulunamadıysa hata mesajı göster
        $answer = "Kullanıcı bulunamadı.";
    }
}

function sendSms($phoneNumber, $message) {
    $apiUrl = 'https://panel4.ekomesaj.com:9588/sms/create'; // SMS API URL'si
    $username = 'teknoyapikimyasal'; // API kullanıcı adı
    $password = 'r1SM5nhSoJ6z'; // API şifresi

    // Basic Authentication için header ayarları
    $credentials = base64_encode("$username:$password");
    $headers = [
        "Authorization: Basic $credentials",
        "Content-Type: application/json" // JSON formatında veri gönderiyoruz
    ];

    // Gönderilecek veri
    $data = json_encode([
        'type' => 1, // 'type' değeri int olarak verilmeli
        'sendingType' => 0, // 'sendingType' değeri int olarak verilmeli
        'title' => 'Title', // Başlık
        'content' => $message, // SMS içeriği
        'number' => $phoneNumber, // Telefon numarası
        'encoding' => 0, // Kodlama türü
        'sender' => 'TEKNOYAPI', // Gönderen başlığı
        'sendingDate' => '', // Gönderim tarihi (eğer ileri tarihli gönderim yapıyorsanız)
        'validity' => 60, // Geçerlilik süresi (dakika cinsinden)
        'commercial' => false, // Ticari gönderim mi?
        'skipAhsQuery' => true, // AHS sorgusu yapılacak mı?
        'recipientType' => 0, // Alıcı türü
        'customID' => '', // Kişisel ID
        'pushSettings' => [
            'url' => 'https://webhook.site/58645951-78ac-4be1-ada2-5ba39662f6f6' // Rapor URL'si
        ]
    ]);

    // cURL işlemleri
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);

    // cURL hatalarını kontrol et
    if (curl_errno($ch)) {
        echo 'Error: ' . curl_error($ch);
    } else {
        // Yanıtı JSON olarak çözümle ve incele
        $responseData = json_decode($response, true);
        
        // JSON çözümleme hatasını kontrol et
        if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($responseData['err']) && $responseData['err'] === null) {
                echo 'MessageId: ' . $responseData['pkgID'] . "\n";
            } else {
                echo 'Status: ' . $responseData['err']['status'] . "\n";
                echo 'Code: ' . $responseData['err']['code'] . "\n";
                echo 'Message: ' . $responseData['err']['message'] . "\n";
            }
        } else {
            echo 'JSON Decode Error: ' . json_last_error_msg() . "\n";
        }
    }

    curl_close($ch);
}

?>

<!DOCTYPE html>
<html data-bs-theme="light" lang="en-US" dir="ltr">

  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Portal</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/favicons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicons/favicon-16x16.png">
    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicons/favicon.ico">
    <link rel="manifest" href="assets/img/favicons/manifest.json">
    <meta name="msapplication-TileImage" content="assets/img/favicons/mstile-150x150.png">
    <meta name="theme-color" content="#ffffff">
    <script src="assets/js/config.js"></script>
    <script src="vendors/simplebar/simplebar.min.js"></script>

    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,500,600,700%7cPoppins:300,400,500,600,700,800,900&amp;display=swap" rel="stylesheet">
    <link href="vendors/simplebar/simplebar.min.css" rel="stylesheet">
    <link href="assets/css/theme-rtl.css" rel="stylesheet" id="style-rtl">
    <link href="assets/css/theme.css" rel="stylesheet" id="style-default">
    <link href="assets/css/user-rtl.css" rel="stylesheet" id="user-style-rtl">
    <link href="assets/css/user.css" rel="stylesheet" id="user-style-default">
  </head>

  <body>
    <main class="main" id="top">
      <div class="container-fluid">
        <div class="row min-vh-100 flex-center g-0">
          <div class="col-lg-8 col-xxl-5 py-3 position-relative"><img class="bg-auth-circle-shape" src="assets/img/icons/spot-illustrations/bg-shape.png" alt="" width="250"><img class="bg-auth-circle-shape-2" src="assets/img/icons/spot-illustrations/shape-1.png" alt="" width="150">
            <div class="card overflow-hidden z-1">
              <div class="card-body p-0">
                <div class="row g-0 h-100">
				<div class="col-md-5 text-center bg-card-gradient">
                    <div class="position-relative p-4 pt-md-5 pb-md-7" data-bs-theme="light">
                      <div class="bg-holder bg-auth-card-shape" style="background-image:url(assets/img/icons/spot-illustrations/half-circle.png);">
                      </div>
                      <div class="z-1 position-relative"><a class="link-light mb-4 font-sans-serif fs-5 d-inline-block fw-bolder" href="#">Portal</a>
                        <p class="opacity-75 text-white">Hoş geldiniz! <br>Email veya kullanıcı adınız (isim.soyisim) ile giriş yapabilirsiniz. </p>
                      </div>
                    </div>
                    <div class="mt-3 mb-4 mt-md-4 mb-md-5" data-bs-theme="light">
                      <p class="pt-3 text-white">Hesabınız yok mu?<br><a class="btn btn-outline-light mt-2 px-4" href="register.php">Hesap oluştur</a></p>
                    </div>
                  </div>
                  <div class="col-md-7 d-flex flex-center">
                    <div class="p-4 p-md-5 flex-grow-1">
                      <div class="row flex-between-center">
                        <div class="col-auto">
                          <h3>Personel Giriş</h3>
                        </div>
                      </div>
						<form action="#" method="post">
                        <div class="mb-3">
                          <div class="form-label">Email / Kullanıcı Adı</div>
                          <input class="form-control" name="email" type="text" required />
                        </div>
                        <div class="mb-3">
                          <div class="d-flex justify-content-between">
                            <div class="form-label">Şifre</div>
                          </div>
                          <input class="form-control" name="password" type="password" required />
                        </div>
						  <div class="row flex-between-center">
							<div class="col-auto">
							  <div class="form-check mb-0">
								<input class="form-check-input" type="checkbox" name="rememberme" checked="checked" />
								<!--<input class="form-check-input" type="checkbox" name="rememberme" />-->
								<label class="form-check-label mb-0" for="rememberme">Beni hatırla</label>
							  </div>
							</div>
							<div class="col-auto"><a class="fs-10" href="forgot-password.php">Şifremi unuttum!</a></div>
						  </div>
                        <div class="mb-3">
                          <button class="btn btn-primary d-block w-100 mt-3" type="submit" name="login">Giriş yap</button>
                        </div>
					  </form>
					  
					  <div class="row flex-between-center">
						<div class="col-auto">
						  <div class="form-check mb-0">
						  </div>
						</div>
						<div class="col-auto"><a class="fs-10" href="kurumsal/login.php">Kurumsal giriş</a></div>
					  </div>
						  
					<?php
					// Hata mesajını göster
					if (!empty($answer)) {
						echo '<div id="dv1" style="background-color: red;"><center><font color="white">' . $answer . '</font></center></div>';
					}
					?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </body>

</html>