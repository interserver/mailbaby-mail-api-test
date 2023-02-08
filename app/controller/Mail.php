<?php
namespace app\controller;

use support\Request;
use support\Response;
use support\Db;
use support\bootstrap\Log;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;
use Respect\Validation\Validator as v;

class Mail
{

	/**
	* returns a json response
	*
	* @param array $body array of data to pass
	* @param int $status status code
	* @return \support\Response
	*/
	public function jsonResponse($body, $status = 200) : Response {
		return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE));
	}

	/**
	* returns a json error response
	*
	* @param string $message the error details
	* @param int $status the error code
	* @return \support\Response
	*/
	public function jsonErrorResponse($message, $status = 200) : Response {
		return new Response($status, ['Content-Type' => 'application/json'], json_encode(['code' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE));
	}

	public function index(Request $request) : Response {
		$accountInfo = $request->accountInfo;
		$orders = Db::table('mail')
			->where('mail_custid', $accountInfo->account_id)
			->get();
		$return = [];
		foreach ($orders as $order) {
			$row = [
				'id' => $order->mail_id,
				'status' => $order->mail_status,
				'username' => $order->mail_username,
			];
			if ($order->mail_comment != '')
				$row['comment'] = $order->mail_comment;
			$return[] = $row;
		}
		return json($return);
	}

	public function view(Request $request, $id) : Response {
		$accountInfo = $request->accountInfo;
		if (!v::intVal()->validate($id))
			return response('The specified ID was invalid.', 400);
		$order = Db::table('mail')
			->where('mail_custid', $accountInfo->account_id)
			->where('mail_id', $id)
			->first();
		$return = [
			'id' => $order->mail_id,
			'status' => $order->mail_status,
			'username' => $order->mail_username,
			'password' => $this->getMailPassword($request, $id),
		];
		if ($order->mail_comment != '')
			$row['comment'] = $order->mail_comment;
		return json($return);
	}

    public function send(Request $request) : Response {
    	if ($request->method() != 'POST')
    		return $this->jsonErrorResponse('This should be a POST request.', 400);
        $accountInfo = $request->accountInfo;
        $id = $request->post('id');
        if (!is_null($id)) {
            if (!v::intVal()->validate($id))
                return $this->jsonErrorResponse('The specified ID was invalid.', 400);
            $order = Db::table('mail')
                ->where('mail_custid', $accountInfo->account_id)
                ->where('mail_id', $id)
                ->where('mail_status', 'active')
                ->first();
            if (is_null($order))
                return $this->jsonErrorResponse('The mail order with the specified ID was not found or not active.', 404);
        } else {
            $order = Db::table('mail')
                ->where('mail_custid', $accountInfo->account_id)
                ->where('mail_status', 'active')
                ->first();
            if (is_null($order))
                return $this->jsonErrorResponse('No active mail order was found.', 404);
            $id = $order->mail_id;
        }
        $sent = false;
        $from = $request->post('from');
        $email = $request->post('body');
        $subject = $request->post('subject');
        $isHtml = strip_tags($email) != $email;
        $who = $request->post('to');
        if (!is_array($who))
            $who = [$who];
        $username = (string)$order->mail_username;
        $password = (string)$this->getMailPassword($request, $id);
        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'utf-8';
        $mailer->isSMTP();
        $mailer->Port = 25;
        $mailer->Host = 'relay.mailbaby.net';
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
        //Enable SMTP debugging
        //SMTP::DEBUG_OFF = off (for production use)
        //SMTP::DEBUG_CLIENT = client messages
        //SMTP::DEBUG_SERVER = client and server messages
        $mailer->SMTPDebug = SMTP::DEBUG_OFF;
        $mailer->Subject = $subject;
        $mailer->isHTML($isHtml);
        try {
            $mailer->setFrom($from);
            $mailer->addReplyTo($from);
            foreach ($who as $to)
                $mailer->addAddress($to);
            $mailer->Body = $email;
            $mailer->preSend();
            if (!$mailer->send()) {
                return json(['status' => 'error', 'text' => $mailer->ErrorInfo]);
            }
            $transId = $mailer->getSMTPInstance()->getLastTransactionID();
            return json(['status' =>'ok', 'text' => $transId]);
        } catch (Exception $e) {
            return json(['status' => 'error', 'text' => $mailer->ErrorInfo]);
        }
    }


    public function advsend(Request $request) : Response {
    	if ($request->method() != 'POST')
    		return $this->jsonErrorResponse('This should be a POST request.', 400);
        $accountInfo = $request->accountInfo;
        if ($request->header('content-type') == 'application/x-www-form-urlencoded') {
        	$data = [];
            foreach (['subject', 'body', 'from', 'to', 'id', 'replyto', 'cc', 'bcc'] as $var) {
				$value = $request->post($var);
				if (!is_null($value)) {
					$data[$var] = $value;
				}
            }
		} else {
			$data = json_decode($request->rawBody(), true);
        }
        $id = isset($data['id']) ? $data['id'] : null;
        if (!is_null($id)) {
            if (!v::intVal()->validate($id))
                return $this->jsonErrorResponse('The specified ID was invalid.', 400);
            $order = Db::table('mail')
                ->where('mail_custid', $accountInfo->account_id)
                ->where('mail_id', $id)
                ->where('mail_status', 'active')
                ->first();
            if (is_null($order))
                return $this->jsonErrorResponse('The mail order with the specified ID was not found or not active.', 404);
        } else {
            $order = Db::table('mail')
                ->where('mail_custid', $accountInfo->account_id)
                ->where('mail_status', 'active')
                ->first();
            if (is_null($order))
                return $this->jsonErrorResponse('No active mail order was found.', 404);
            $id = $order->mail_id;
        }
        foreach (['from', 'to', 'subject', 'body'] as $field)
            if (!isset($data[$field]))
                return $this->jsonErrorResponse('Missing the required "'.$field.'" field', 404);


        $sent = false;
        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'utf-8';
        $mailer->isSMTP();
        $mailer->Port = 25;
        $mailer->Host = 'relay.mailbaby.net';
        $mailer->SMTPAuth = true;
        $mailer->Username = (string)$order->mail_username;
        $mailer->Password = (string)$this->getMailPassword($request, $id);
        //Enable SMTP debugging
        //SMTP::DEBUG_OFF = off (for production use)
        //SMTP::DEBUG_CLIENT = client messages
        //SMTP::DEBUG_SERVER = client and server messages
        $mailer->SMTPDebug = SMTP::DEBUG_OFF;
        $mailer->Subject = $data['subject'];
        $mailer->isHTML(strip_tags($data['body']) != $data['body']);
        try {
            $mailer->setFrom($data['from']['email'], isset($data['from']['name']) ? $data['from']['name'] : '');
            foreach ($data['to'] as $contact)
                $mailer->addAddress($contact['email'], isset($contact['name']) ? $contact['name'] : '');
            foreach (['ReplyTo', 'CC', 'BCC'] as $type) {
                if (isset($data[strtolower($type)])) {
                    if (is_array($data[strtolower($type)])) {
                        if (count($data[strtolower($type)]) > 0) {
                            foreach ($data[strtolower($type)] as $contact) {
                                $call = 'add'.$type;
                                $mailer->$call($contact['email'], isset($contact['name']) ? $contact['name'] : '');
                            }
                        }
                    } else {
                        return $this->jsonErrorResponse('The "'.strtolower($type).'" field is supposed to be an array.', 404);
                    }
                }
            }
            if (isset($data['attachments'])) {
                if (is_array($data['attachments'])) {
                    if (count($data['attachments']) > 0) {
                        foreach ($data['attachments'] as $idx => $attachment) {
                            $fileData = base64_decode($attachment['data']);
                            $localFile = tempnam(sys_get_temp_dir(), 'attachment');
                            file_put_contents($localFile, $fileData);
                            $mailer->addAttachment($localFile, isset($attachment['filename']) ? $attachment['filename'] : '');
                        }
                    }
                } else {
                    return $this->jsonErrorResponse('The "attachments" field is supposed to be an array.', 404);
                }
            }
            $mailer->Body = $data['body'];
            $mailer->preSend();
            if (!$mailer->send()) {
                return json(['status' => 'error', 'text' => $mailer->ErrorInfo]);
            }
            // SERVER -> CLIENT: 250 Message queued as 185caa69ff7000f47c
            $transId = $mailer->getSMTPInstance()->getLastTransactionID();
            return json(['status' =>'ok', 'text' => $transId]);
        } catch (Exception $e) {
            return json(['status' => 'error', 'text' => $mailer->ErrorInfo]);
        }
    }

	public function log(Request $request) {
		$accountInfo = $request->accountInfo;
		$id = $request->get('id', null);
		$limit = $request->get('limit', 100);
		$skip = $request->get('skip', 0);
        $startDate = $request->get('startDate', null);
        $endDate = $request->get('endDate', null);
        $origin = $request->get('origin', null);
        $mx = $request->get('mx', null);
        $from = $request->get('from', null);
        $to = $request->get('to', null);
        $subject = $request->get('subject', null);
        $mailId = $request->get('mailid', null);
        if (!v::anyOf(v::stringType()->length(18, 19), v::nullType())->validate($mailId))
            return $this->jsonErrorResponse('The specified mailid value was not a valid email id.', 400);
        if (!v::anyOf(v::email(), v::nullType())->validate($from))
            return $this->jsonErrorResponse('The specified from value was not a valid email address.', 400);
        if (!v::anyOf(v::email(), v::nullType())->validate($to))
            return $this->jsonErrorResponse('The specified from value was not a valid email address.', 400);
        if (!v::anyOf(v::domain(), v::nullType())->validate($mx))
            return $this->jsonErrorResponse('The specified mx value was not a valid hostname.', 400);
        if (!v::anyOf(v::ip(), v::nullType())->validate($origin))
            return $this->jsonErrorResponse('The specified origin value was not a valid IP address.', 400);
        if (!v::anyOf(v::intVal(), v::nullType())->validate($startDate))
            return $this->jsonErrorResponse('The specified startDate value '.var_export($startDate).' was invalid.', 400);
        if (!v::anyOf(v::intVal(), v::nullType())->validate($endDate))
            return $this->jsonErrorResponse('The specified endDate value '.var_export($endDate).' was invalid.', 400);
        if (!v::intVal()->validate($skip))
            return $this->jsonErrorResponse('The specified skip value was invalid.', 400);
        if (!v::intVal()->validate($limit))
            return $this->jsonErrorResponse('The specified limit value was invalid.', 400);
		if (!is_null($id)) {
			if (!v::intVal()->validate($id))
				return $this->jsonErrorResponse('The specified ID was invalid.', 400);
			$order = Db::table('mail')
				->where('mail_custid', $accountInfo->account_id)
				->where('mail_id', $id)
				->where('mail_status', 'active')
				->first();
			if (is_null($order))
				return $this->jsonErrorResponse('The mail order with the specified ID was not found or not active.', 404);
		} else {
			$order = Db::table('mail')
				->where('mail_custid', $accountInfo->account_id)
				->where('mail_status', 'active')
				->first();
			if (is_null($order))
				return $this->jsonErrorResponse('No active mail order was found.', 404);
		}
		$id = $order->mail_id;
        $where = [];
        $where[] = ['mail_messagestore.user', '=', 'mb'.$id];
        if (!is_null($startDate))
            $where[] = ['mail_messagestore.time', '>=', (int)$startDate];
        if (!is_null($endDate))
            $where[] = ['mail_messagestore.time', '<=', (int)$endDate];
        if (!is_null($origin))
            $where[] = ['mail_messagestore.origin', '=', $origin];
        if (!is_null($mx))
            $where[] = ['mail_senderdelivered.mxHostname', '=', $mx];
        if (!is_null($from))
            $where[] = ['mail_messagestore.from', '=', $from];
        if (!is_null($to))
            $where[] = ['mail_messagestore.to', '=', $to];
        if (!is_null($mailId))
            $where[] = ['mail_messagestore.id', '=', $mailId];
        if (!is_null($subject))
            $where[] = ['mail_headers.value', '=', $subject];
   		$total = Db::connection('zonemta')
   			->table('mail_messagestore')
			->where($where)
			->count();
        //error_log('Mail Total:'.$total);
		$return = [
			'total' => $total,
			'skip' => $skip,
			'limit' => $limit,
			'emails' => []
		];
   		$orders = Db::connection('zonemta')
   			->table('mail_messagestore')
   			->leftJoin('mail_headers', function ($join) {
                $join->on('mail_messagestore.id', '=', 'mail_headers.id')
                    ->on('mail_headers.field','=', Db::raw('"subject"'));
            })
   			->leftJoin('mail_senderdelivered', 'mail_messagestore.id', '=', 'mail_senderdelivered.id')
            ->select('mail_messagestore._id', 'mail_messagestore.id', 'mail_messagestore.from', 'mail_messagestore.to', 'mail_headers.value AS subject',
                'mail_messagestore.created', 'mail_messagestore.time', 'mail_messagestore.user', 'mail_messagestore.transtype', 'mail_messagestore.origin',
                'mail_messagestore.interface', 'mail_senderdelivered.sendingZone', 'mail_senderdelivered.bodySize', 'mail_senderdelivered.seq', 'mail_senderdelivered.recipient',
                'mail_senderdelivered.domain', 'mail_senderdelivered.locked', 'mail_senderdelivered.lockTime', 'mail_senderdelivered.assigned', 'mail_senderdelivered.queued',
                'mail_senderdelivered.mxHostname', 'mail_senderdelivered.response')
            ->where($where)
			->offset($skip)
			->limit($limit)
			->get();
		$return['emails'] = $orders->all();
		return json($return);
	}

	public function viewtest(Request $request)
	{
		return view('index/view', ['name' => 'webman']);
	}

	public function json(Request $request)
	{
		return json(['code' => 0, 'msg' => 'ok']);
	}

	public function file(Request $request)
	{
		$file = $request->file('upload');
		if ($file && $file->isValid()) {
			$file->move(public_path().'/files/myfile.'.$file->getUploadExtension());
			return json(['code' => 0, 'msg' => 'upload success']);
		}
		return json(['code' => 1, 'msg' => 'file not found']);
	}

	/**
	* returns the current password for a mail account
	*
	* @param Request $request
	* @param int $id
	* @return null|string the current password or null on no matching password
	*/
	private function getMailPassword(Request $request, $id) {
		$password = Db::table('history_log')
			->where('history_type', 'password')
			->where('history_section', 'mail')
			->where('history_creator', $request->accountInfo->account_id)
			->where('history_new_value', $id)
			->orderBy('history_timestamp', 'desc')
			->first('history_old_value');
		return $password->history_old_value;
	}
}
