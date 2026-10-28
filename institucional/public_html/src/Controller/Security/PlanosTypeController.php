<?php

/**
 *  (c) Rogério Adriano da Silva <rogerioadris.silva@gmail.com>.
 */
namespace LuzPropria\LuzPanel\Controller\Security;

use LuzPropria\LuzPanel\Controller\ContainerAware;
use LuzPropria\LuzPanel\Form\PlanosTypeForm;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

class PlanosTypeController extends ContainerAware
{


    public function indexPlanosBeneficiosAction($id_planos)
    {
        $planos_type = $this->get('db')->fetchAssoc("SELECT * FROM planos_type where id = ? ",array($id_planos));
        $planos_beneficios = $this->get('db')->fetchAll("SELECT
                                                            bt.*, pb.id_beneficio
                                                        FROM
                                                            planos_beneficios pb
                                                        RIGHT OUTER JOIN beneficios_type bt ON pb.id_beneficio = bt.id
                                                        and
                                                            pb.id_plano = ?",array($id_planos));

        return $this->render('planos_beneficios.twig', array(
            'data' => $planos_beneficios,
            'planos_type' => $planos_type,
        ));


    }

    public function SavePlanosBeneficios(Request $request)
    {
        $data = $request->request->all();
        // dd($data);

        if($data["status"] == "true"){
            $insert_query = 'INSERT INTO `planos_beneficios` (`id_plano`, `id_beneficio`, `created_at`, `updated_at`) VALUES (?, ?, NOW(), NOW())';
            $this->db()->executeUpdate($insert_query, array($data['id_plano'], $data['beneficio']));
        }
        else{
            $this->get('db')->executeUpdate('DELETE FROM `planos_beneficios` WHERE `id_plano` = ? and id_beneficio = ?', array($data['id_plano'], $data['beneficio']));
        }

        return true;
    }
    /**
     * Lista.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $planos_type = $this->db()->fetchAll('SELECT * FROM `planos_type`');


        return $this->render('list.twig', array(
            'data' => $planos_type,
        ));
    }

    /**
     * Adicionar.
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function createAction(Request $request)
    {
        $planos_type = array();

        $form = $this->createForm(new PlanosTypeForm(), $planos_type, array(
            'action' => $this->get('url_generator')->generate('s_planos_type_create'),
            'method' => 'POST',
        ));

        if ('POST' == $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $data = $form->getData();

                $data['url'] = $this->get('slugify')->slugify($data['url']);

                try {
                    $insert_query = 'INSERT INTO `planos_type` (`title`, `url`, `body`, `enabled`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, NOW(), NOW())';
                    $this->db()->executeUpdate($insert_query, array($data['title'], $data['url'], $data['body'], $data['enabled']));

                    $this->flashMessage()->add('success', array('message' => 'Adicionado com sucesso.'));

                    return $this->redirect('s_planos_type');
                } catch (UniqueConstraintViolationException $e) {
                    $this->flashMessage()->add('danger', array('message' => 'Url já está em uso.'));
                }
            }
        }

        return $this->render('create.twig', array(
            'form' => $form->createView(),
        ));
    }

    /**
     * Editar.
     *
     * @param Request $request
     * @param mixed   $id
     *
     * @return \Symfony\Component\HttpFoundation\Response|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function editAction(Request $request, $id)
    {
        $planos_type = $this->get('db')->fetchAssoc('SELECT * FROM `planos_type` WHERE `id` = ? LIMIT 1;', array($id));

        if ($planos_type === false) {
            $this->flashMessage()->add('warning', array('message' => 'Desculpe, mais a pagina não foi encontrada.'));

            return $this->redirect('s_planos_type');
        }

        $form = $this->createForm(new PlanosTypeForm(), $planos_type, array(
            'action' => $this->get('url_generator')->generate('s_planos_type_edit', array('id' => $id)),
            'method' => 'POST',
        ));

        if ('POST' == $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $data = $form->getData();

                $data['url'] = $this->get('slugify')->slugify($data['url']);

                try {
                    $update_query = 'UPDATE `planos_type` SET `title` = ?, `url` = ?, `body` = ?, `enabled` = ?, `updated_at` = NOW() WHERE `id` = ?';
                    $this->get('db')->executeUpdate($update_query, array($data['title'], $data['url'], $data['body'], $data['enabled'], $data['id']));

                    $this->flashMessage()->add('success', array('message' => 'Editado com sucesso.'));

                    return $this->redirect('s_planos_type');
                } catch (UniqueConstraintViolationException $e) {
                    $this->flashMessage()->add('danger', array('message' => 'Url já está em uso.'));
                }
            }
        }

        return $this->render('edit.twig', array(
            'form' => $form->createView(),
            'id' => $id,
        ));
    }

    /**
     * Deletar.
     *
     * @param Request $request
     * @param int     $id
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction(Request $request)
    {
        $id = $request->request->get('id');
        $row_sql = $this->get('db')->fetchAssoc('SELECT * FROM `planos_type` WHERE `id` = ? LIMIT 1;', array($id));

        if ($row_sql === false) {
            $this->flashMessage()->add('warning', array('message' => 'Desculpe, mais não foi encontrado.'));
        } else {
            $this->get('db')->executeUpdate('DELETE FROM `planos_type` WHERE `id` = ?', array($id));

            $this->flashMessage()->add('success', array('message' => 'Deletado com sucesso.'));
        }

        return $this->redirect('s_planos_type');
    }
}
