<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Controller\Front;

use LuzPropria\LuzPanel\Portal\{PortalApi,PortalApiException,PortalResult};
use Symfony\Component\HttpFoundation\{Request,Response,RedirectResponse,JsonResponse};
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\{CsrfToken,CsrfTokenManagerInterface};
use Twig\Environment;

class PortalController
{
    public function __construct(private PortalApi $api,private Environment $twig,private UrlGeneratorInterface $urls,private CsrfTokenManagerInterface $csrf) {}

    public function handle(Request $r): Response
    {
        $action=(string)$r->attributes->get('portal_action');
        // O login do cliente fica na gestão; esta página não existe mais no site.
        if ($action==='login') return new RedirectResponse($this->api->managementUrl().'/entrar',302);
        if (!$r->isMethodSafe() && !$this->csrf->isTokenValid(new CsrfToken('portal_action',(string)$r->request->get('_token')))) {
            return $this->page($r,'error',['message'=>'Sua sessão expirou. Recarregue a página e tente novamente.'],419);
        }
        try {
            $response=match($action) {
                'home'=>$this->home($r),'signup'=>$this->signup($r),
                'subscribe'=>$this->subscribe($r),'logout'=>$this->logout($r),'forgot'=>$this->forgot($r),'reset'=>$this->reset($r),
                'verify'=>$this->verify($r),'resend'=>$this->resend($r),'resendmail'=>$this->resendMail($r),'confirmed'=>$this->page($r,'confirmed',['ok'=>false,'login_url'=>$this->api->managementUrl().'/entrar']),'pet'=>$this->pet($r),'check'=>$this->check($r),
                'postal'=>new JsonResponse($this->call($r,'GET','postal/'.$r->attributes->get('zip'),[],false)->data),
                'proposal'=>$this->proposal($r),'review'=>$this->pdf($r,'proposals/'.$r->attributes->get('id').'/pdf'),
                'document'=>$this->pdf($r,'documents/'.$r->attributes->get('id')),
                'accept'=>$this->accept($r),'generate'=>$this->generate($r),'checkout'=>$this->payment($r,'subscriptions','checkout'),
                'invoice'=>$this->payment($r,'invoices','payment'),'cancel'=>$this->cancel($r),
                default=>$this->page($r,'error',['message'=>'Página não encontrada.'],404),
            };
        } catch(PortalApiException $e) {
            if ($e->result->status===401) {
                $r->getSession()->remove('portal_token');
                $r->getSession()->getFlashBag()->add('portal_error','Entre novamente para continuar.');
                $response=$this->redirect('portal_login');
            } elseif ($r->isMethod('POST') && $action!=='generate') {
                if ($action==='reset') {
                    $r->getSession()->getFlashBag()->add('portal_error','Confira os dados e a confirmação da senha. Se o link expirou, solicite outro.');
                    return $this->resetError($r);
                }
                // Passwords and recovery tokens are never copied into flash/session form data.
                $fields=['name','document','email','phone','rg','street','number','complement','neighborhood','city','state','zip',
                    'pet_name','pet_species','pet_birth_date','pet_breed','pet_color','pet_sex','species','birth_date','breed','color','sex','reason','signer_name','acquisition_source','referrer_name'];
                $old=array_intersect_key($r->request->all(),array_flip($fields));
                $r->getSession()->set('portal_old',$old);
                $errors=$e->result->data['errors']??[];
                $messages=[];
                foreach(is_array($errors)?$errors:[] as $items) foreach(is_array($items)?$items:[] as $message) if(is_string($message))$messages[]=$message;
                if (!$messages) $messages[]=$e->result->data['message']??'Não foi possível concluir. Confira as informações e tente novamente.';
                foreach(array_slice($messages,0,12) as $message)$r->getSession()->getFlashBag()->add('portal_error',mb_substr((string)$message,0,500));
                $response=$this->postBack($r,$action);
            } else {
                $response=$this->page($r,'error',['message'=>$e->result->status===404?'Este registro não está disponível para sua conta.':($e->result->data['message']??'Não foi possível carregar esta página.')],$e->result->status);
            }
        }
        $response->headers->set('Cache-Control','private, no-store');
        $response->headers->set('Referrer-Policy','no-referrer');
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('X-Frame-Options','SAMEORIGIN');
        return $response;
    }

    private function call(Request $r,string $method,string $path,array $data=[],bool $auth=true,bool $pdf=false): PortalResult
    {
        $token=$auth?$r->getSession()->get('portal_token'):null;
        if ($auth && !is_string($token)) throw new PortalApiException(new PortalResult(401));
        $result=$this->api->request($method,$path,$data,$token,['ip'=>$r->getClientIp(),'agent'=>$r->headers->get('User-Agent'),
            'port'=>$r->server->get('REMOTE_PORT'),'language'=>$r->headers->get('Accept-Language')],$pdf);
        if (!$result->ok()) throw new PortalApiException($result);
        return $result;
    }

    private function page(Request $r,string $view,array $data=[],int $status=200): Response
    {
        $old=$r->getSession()->remove('portal_old')??[];
        return new Response($this->twig->render('portal/'.$view.'.twig',$data+[
            'logged_in'=>(bool)$r->getSession()->get('portal_token'),'csrf'=>$this->csrf->getToken('portal_action')->getValue(),
            'errors'=>$r->getSession()->getFlashBag()->get('portal_error'),'messages'=>$r->getSession()->getFlashBag()->get('portal_success'),'old'=>$old,
        ]),$status,['Cache-Control'=>'private, no-store','Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff','X-Frame-Options'=>'SAMEORIGIN']);
    }

    private function redirect(string $route,array $params=[]): RedirectResponse { return new RedirectResponse($this->urls->generate($route,$params),303); }
    private function success(Request $r,string $message): void { $r->getSession()->getFlashBag()->add('portal_success',$message); }
    private function establish(Request $r,array $data): void
    {
        $r->getSession()->migrate(true);
        $r->getSession()->set('portal_token',$data['token']);
    }

    // A área do cliente fica na gestão (planos, faturas e pets); o site cuida só da contratação.
    private function home(Request $r): Response
    {
        return new RedirectResponse($this->api->managementUrl().'/portal',302);
    }

    private function existingLoginUrl(Request $r,array $data): string
    {
        $login=($data['by']??'')==='document'?preg_replace('/\D/','',(string)$r->request->get('document')):trim((string)$r->request->get('email'));
        return $this->api->managementUrl().'/entrar?'.http_build_query(['cadastro'=>'existente','login'=>mb_substr((string)$login,0,255)]);
    }

    // Chamada do cadastro (etapa "Seus dados"): avisa cedo se o CPF ou e-mail já têm acesso.
    private function check(Request $r): Response
    {
        try {
            $data=$this->call($r,'POST','account-check',['document'=>(string)$r->request->get('document'),'email'=>trim((string)$r->request->get('email'))],false)->data;
        } catch(PortalApiException) {
            return new JsonResponse(['ok'=>true]); // sem resposta da conta: segue, o envio final confere de novo
        }
        return match($data['code']??'') {
            'ACCOUNT_EXISTS'=>new JsonResponse(['redirect'=>$this->existingLoginUrl($r,$data)]),
            'CUSTOMER_WITHOUT_ACCESS'=>new JsonResponse(['message'=>(string)($data['message']??'')]),
            default=>new JsonResponse(['ok'=>true]),
        };
    }

    // Reenvio pelo e-mail, para quem abriu um link vencido sem estar com a sessão aberta.
    private function resendMail(Request $r): Response
    {
        try { $this->call($r,'POST','verification-resend',['email'=>trim((string)$r->request->get('email'))],false); }
        catch(PortalApiException) { $this->success($r,'Informe um e-mail válido.'); return $this->redirect('portal_confirmed'); }
        $this->success($r,'Se o e-mail estiver cadastrado e ainda não confirmado, enviamos um novo link.');
        return $this->redirect('portal_confirmed');
    }

    private function catalog(Request $r): array { return $this->call($r,'GET','catalog',[],false)->data['offers']??[]; }
    private function offer(Request $r,string $code): array
    {
        foreach($this->catalog($r) as $offer) if($offer['code']===$code)return $offer;
        throw new PortalApiException(new PortalResult(404,['message'=>'Este plano não está disponível para contratação.']));
    }

    private function signup(Request $r,?array $offer=null): Response
    {
        if ($r->getSession()->get('portal_token')) return $this->redirect('portal_home');
        if ($r->isMethod('POST')) {
            $data=$r->request->all(); unset($data['_token']);
            $data['product_version_id']=empty($offer['informational'])?($offer['id']??null):null;
            try {
                $result=$this->call($r,'POST','register',$data,false)->data;
            } catch (PortalApiException $e) {
                if ($e->result->status!==409 || ($e->result->data['code']??'')!=='ACCOUNT_EXISTS') throw $e;
                // Já tem acesso: vai para o login (na gestão) com o CPF ou e-mail preenchido.
                return new RedirectResponse($this->existingLoginUrl($r,$e->result->data),302);
            }
            $this->establish($r,$result);
            $r->getSession()->remove('portal_plan_code');
            if (!empty($result['proposal_id']))$r->getSession()->set('portal_pending_proposal',$result['proposal_id']);
            return !empty($result['proposal_id'])
                ?$this->redirect('portal_proposal',['id'=>$result['proposal_id']]):$this->redirect('portal_home');
        }
        return $this->page($r,'signup',['offer'=>$offer]);
    }

    private function subscribe(Request $r): Response
    {
        $code=(string)$r->attributes->get('code');
        $offer=$this->offer($r,$code);
        $r->getSession()->set('portal_plan_code',$code);
        if (!$r->getSession()->get('portal_token')) return $this->signup($r,$offer);
        $account=$this->call($r,'GET','me')->data;
        if (!empty($offer['informational'])) {
            $r->getSession()->remove('portal_plan_code');
            $this->success($r,'Seu cadastro está pronto. A contratação do plano '.$offer['name'].' será liberada em breve; avisaremos você.');
            return $this->redirect('portal_home');
        }
        if ($r->isMethod('POST')) {
            $pet=(int)$r->request->get('pet_id');
            foreach($account['proposals'] as $p) if((int)$p['pet_id']===$pet && (int)$p['version_id']===(int)$offer['id']) return $this->redirect('portal_proposal',['id'=>$p['id']]);
            $p=$this->call($r,'POST','proposals',['pet_id'=>$pet,'product_version_id'=>$offer['id']])->data;
            $r->getSession()->remove('portal_plan_code');
            return $this->redirect('portal_proposal',['id'=>$p['id']]);
        }
        return $this->page($r,'choose-pet',['offer'=>$offer,'account'=>$account]);
    }

    private function logout(Request $r): Response
    {
        try { $this->call($r,'POST','logout'); } catch(PortalApiException) {}
        foreach(['portal_token','portal_pending_proposal','portal_plan_code','portal_old'] as $key)$r->getSession()->remove($key);
        $r->getSession()->migrate(true);
        return $this->redirect('portal_login');
    }

    private function forgot(Request $r): Response
    {
        if ($r->isMethod('POST')) {
            $result=$this->call($r,'POST','forgot-password',['email'=>(string)$r->request->get('email')],false);
            $this->success($r,$result->data['message']);
            return $this->redirect('portal_forgot');
        }
        return $this->page($r,'password',['reset'=>false,'reset_token'=>'','reset_email'=>'']);
    }

    private function reset(Request $r): Response
    {
        if ($r->isMethod('POST')) {
            $result=$this->call($r,'POST','reset-password',array_intersect_key($r->request->all(),array_flip(['token','email','password','password_confirmation'])),false);
            $r->getSession()->remove('portal_token'); $this->success($r,$result->data['message']);
            return $this->redirect('portal_login');
        }
        return $this->page($r,'password',['reset'=>true,'reset_token'=>(string)$r->query->get('token'),'reset_email'=>(string)$r->query->get('email')]);
    }

    private function verify(Request $r): Response
    {
        try {
            $confirmed=$this->call($r,'GET','verify-email/'.$r->attributes->get('user').'/'.$r->attributes->get('hash'),
                ['expires'=>(string)$r->query->get('expires'),'signature'=>(string)$r->query->get('signature')],false)->data;
        } catch(PortalApiException) {
            return $this->page($r,'confirmed',['ok'=>false,'login_url'=>$this->api->managementUrl().'/entrar'],403);
        }
        // Com a sessão do site aberta, segue a contratação; em outro navegador, mostra a confirmação e leva ao login.
        if ($r->getSession()->get('portal_token')) {
            $this->success($r,'E-mail confirmado. Você já pode continuar sua contratação.');
            $id=$r->getSession()->remove('portal_pending_proposal');
            return $id?$this->redirect('portal_proposal',['id'=>$id]):$this->redirect('portal_home');
        }
        // Em outro navegador: entra direto na conta pelo link de uso único da gestão (só aceito se for do endereço da gestão).
        $enter=(string)($confirmed['login_url']??'');
        if (str_starts_with($enter,$this->api->managementUrl().'/')) return new RedirectResponse($enter,302);
        return $this->page($r,'confirmed',['ok'=>true,'login_url'=>$this->api->managementUrl().'/entrar']);
    }

    private function resend(Request $r): Response
    {
        $result=$this->call($r,'POST','verification'); $this->success($r,$result->data['message']);
        return $this->redirect('portal_home');
    }

    private function pet(Request $r): Response
    {
        $account=$this->call($r,'GET','me')->data;
        if ($r->isMethod('POST')) {
            $this->call($r,'POST','pets',array_intersect_key($r->request->all(),array_flip(['name','species','breed','birth_date','color','sex'])));
            $this->success($r,'Seu pet foi cadastrado.');
            $code=$r->getSession()->get('portal_plan_code');
            return $code?$this->redirect('portal_subscribe',['code'=>$code]):$this->redirect('portal_home');
        }
        return $this->page($r,'pet');
    }

    private function resetError(Request $r): Response
    {
        $response=$this->page($r,'password',['reset'=>true,'reset_token'=>(string)$r->request->get('token'),'reset_email'=>(string)$r->request->get('email')],422);
        $response->headers->set('Cache-Control','private, no-store');
        $response->headers->set('Referrer-Policy','no-referrer');
        return $response;
    }

    private function proposal(Request $r): Response
    {
        $p=$this->call($r,'GET','proposals/'.$r->attributes->get('id'))->data;
        // Right after signing, the customer goes straight to the Vindi checkout once the contract is ready.
        $auto=(int)$r->getSession()->get('portal_checkout_after_signing')===(int)($p['id']??0) && !empty($p['can_checkout']);
        if ($auto) $r->getSession()->remove('portal_checkout_after_signing');
        return $this->page($r,'proposal',['proposal'=>$p,'auto_checkout'=>$auto]);
    }

    private function pdf(Request $r,string $path): Response
    {
        @set_time_limit(140);
        $result=$this->call($r,'GET',$path,[],true,true);
        if (!str_starts_with($result->body,'%PDF-')) throw new PortalApiException(new PortalResult(503,['message'=>'O PDF está em preparação. Tente novamente.']));
        return new Response($result->body,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="contrato.pdf"']);
    }

    private function accept(Request $r): Response
    {
        $this->call($r,'POST','proposals/'.$r->attributes->get('id').'/accept',array_intersect_key($r->request->all(),array_flip(['accepted','signer_name','fingerprint','signature_data','device_info'])));
        $r->getSession()->set('portal_checkout_after_signing',(int)$r->attributes->get('id'));
        $this->success($r,'Assinatura registrada. Estamos preparando seu contrato e, em seguida, você segue para o pagamento.');
        return $this->redirect('portal_proposal',['id'=>$r->attributes->get('id')]);
    }

    private function generate(Request $r): Response
    {
        @set_time_limit(140);
        // Both transports use the longer conversion timeout, but this endpoint returns JSON.
        $result=$this->api->request('POST','proposals/'.$r->attributes->get('id').'/document',[],
            $r->getSession()->get('portal_token'),['ip'=>$r->getClientIp(),'agent'=>$r->headers->get('User-Agent')],true);
        if ($result->ok()) {
            if ($r->getPreferredFormat()==='json')return new JsonResponse(['ready'=>true]);
            $this->success($r,'Seu contrato está pronto.');
        } else {
            if ($r->getPreferredFormat()==='json')return new JsonResponse(['ready'=>false,'message'=>$result->data['message']??'O PDF ainda está em preparação. Sua assinatura permanece salva.'],503);
            $r->getSession()->getFlashBag()->add('portal_error',$result->data['message']??'O PDF ainda está em preparação.');
        }
        return $this->redirect('portal_proposal',['id'=>$r->attributes->get('id')]);
    }

    private function payment(Request $r,string $entity,string $operation): Response
    {
        $data=$operation==='checkout'?array_intersect_key($r->request->all(),['payment_method'=>true]):[];
        $result=$this->call($r,'POST',$entity.'/'.$r->attributes->get('id').'/'.$operation,$data)->data;
        $url=(string)($result['url']??''); $parts=parse_url($url);
        if (!$this->api->paymentAllowed($url)) throw new PortalApiException(new PortalResult(422,['message'=>'O pagamento está em conferência. Consulte sua unidade.']));
        return new RedirectResponse($url,303);
    }

    private function cancel(Request $r): Response
    {
        $result=$this->call($r,'POST','subscriptions/'.$r->attributes->get('id').'/cancellation',['reason'=>(string)$r->request->get('reason')]);
        $this->success($r,$result->data['message']); return $this->redirect('portal_home');
    }

    private function postBack(Request $r,string $action): Response
    {
        return match($action) {
            'login'=>$this->redirect('portal_login'),'signup'=>$this->redirect('portal_signup'),
            'subscribe'=>$this->redirect('portal_subscribe',['code'=>$r->attributes->get('code')]),
            'forgot'=>$this->redirect('portal_forgot'),'reset'=>$this->redirect('portal_reset'),
            'pet'=>$this->redirect('portal_pet'),'accept'=>$this->redirect('portal_proposal',['id'=>$r->attributes->get('id')]),
            default=>$this->redirect('portal_home'),
        };
    }
}
