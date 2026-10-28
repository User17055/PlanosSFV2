<?php

/*
 *  (c) Rogério Adriano da Silva <rogerioadris.silva@gmail.com>
 */

namespace LuzPropria\LuzPanel\Controller\Security;

use LuzPropria\LuzPanel\Controller\ContainerAware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class SecurityController.
 */
class SecurityController extends ContainerAware
{
    /**
     * Pagina inicial.
     */
    public function indexAction()
    {
        return $this->render('/security/index.twig');
    }

    /**
     * Pagina de login.
     */
    public function loginAction(Request $request)
    {
        $app = $this->getContainer();

        return $this->render('/security/login.twig', array(
                'error' => (new \Symfony\Component\Security\Http\Authentication\AuthenticationUtils($this->get('request_stack')))->getLastAuthenticationError()?->getMessageKey(), // Exibir mensagem de erro
                'last_username' => (new \Symfony\Component\Security\Http\Authentication\AuthenticationUtils($this->get('request_stack')))->getLastUsername(), // Preencher campo com último nome de usuário informado
            )
        );
    }
}
