<?php


require 'PHPMailer-master/PHPMailerAutoload.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $userEmail = $_POST["email"]; // Get user's email address
    $password = getPasswordFromDatabase($userEmail);

    if ($password !== false) {
        $mail = new PHPMailer;

        // SMTP credentials come from environment variables — never hardcode secrets.
        // Set JOURNEYAI_SMTP_USER / JOURNEYAI_SMTP_PASS / JOURNEYAI_SMTP_FROM before use.
        $smtpUser = getenv('JOURNEYAI_SMTP_USER') ?: '';
        $smtpPass = getenv('JOURNEYAI_SMTP_PASS') ?: '';
        $smtpFrom = getenv('JOURNEYAI_SMTP_FROM') ?: $smtpUser;

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->Port = 587;
        $mail->SMTPSecure = 'tls';
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;

        $mail->setFrom($smtpFrom, 'JourneyAI');
        $mail->addAddress($userEmail);
        $mail->addReplyTo($smtpFrom, 'JourneyAI');

        $mail->isHTML(true);
        $mail->Subject = 'JourneyAI — password reset';
        // NOTE: passwords are hashed and cannot be recovered. This is a stub; a
        // production reset flow would email a one-time reset link instead.
        $mail->Body = "A password reset was requested for your JourneyAI account.";

        if ($mail->send()) {
            echo 'Password sent to your email address.';
        } else {
            echo 'Unable to send password. Please try again later.';
        }
    } else {
        echo 'No password found for the provided email address.';
    }
}

function getPasswordFromDatabase($email) {
    // Replace these database connection details with your actual values
    $server = "localhost";
    $user = "root";
    $pass = "";
    $db = "project";

        $conn = mysqli_connect($server,$user,$pass,$db);

        if($conn){
            
        }
        else{
            echo "unsuccesfull!";
        }
    // Fetch password from the database
    $sql = "SELECT psw FROM signup WHERE eml = '$email'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $databasePassword = $row["psw"];
        $conn->close();
        return $databasePassword;
    } else {
        $conn->close();
        return false;
    }
}

?>
