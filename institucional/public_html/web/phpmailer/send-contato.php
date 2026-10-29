<?php
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); echo json_encode(['ok' => false]); exit; }
    $queryString = File_get_contents("php://input");
    $data = array();
    parse_str($queryString, $data);

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';
    $mailConfig = require dirname(__DIR__, 3).'/configuracao_privada/mail.php';

    if(
        !empty($data['iNome']) &&
        !empty($data['iCidade']) &&
        !empty($data['iTelefone']) &&
        !empty($data['iMensagem'])  	
    ){
        try {

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            $mail->IsSMTP(); 
            $mail->IsHTML(TRUE);
            $mail->SMTPDebug = 0;
            $mail->SMTPAuth = true;
            $mail->Host = $mailConfig['Host'];
            $mail->Username = $mailConfig['Username'];
            $mail->Password = $mailConfig['Password']; 
            $mail->Port = $mailConfig['Port']; 
            $mail->SMTPSecure = $mailConfig['SMTPSecure'];
            $mail->CharSet = 'UTF-8';

            $mail->From = $mailConfig['From'];
            $mail->FromName = $mailConfig['FromName'];		
            $mail->AddAddress($mailConfig['To'] ?? "luan@luzpropria.com");
            $mail->Subject = "Contato via Site - São Francisco Vet";
            
            $mail->Body = '<br>Nome: '. $data['iNome'];
            $mail->Body .= '<br>E-mail: '. $data['iCidade'];
            $mail->Body .= '<br>Telefone: '. $data['iTelefone'];
            $mail->Body .= '<br>Mensagem: '. $data['iMensagem'];		

            $mail->Send();


            http_response_code(200);

            $msgExibir = array(
                'status' => 'success',
                'code' => '200',
                'message' => 'E-mail enviado com sucesso'
            );
        }
        catch (\PHPMailer\PHPMailer\Exception $e) {

            http_response_code(403);
            $msgExibir = array(
                'status' => 'error',
                'code' => '1',
                'message' => $e->errorMessage()
            );
        }
        catch (Exception $e) {

            http_response_code(403);
            $msgExibir = array(
                'status' => 'error',
                'code' => '2',
                'message' => $e->getMessage()
            );
        }
    }else{

        http_response_code(403);
        $msgExibir = array(
            'status' => 'error',
            'code' => '3',
            'message' => 'Paramentos incorretos'
        );
    }

    echo json_encode($msgExibir);
?>