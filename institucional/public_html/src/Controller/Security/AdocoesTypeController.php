<?php

/**
 *  (c) Rogério Adriano da Silva <rogerioadris.silva@gmail.com>.
 */
namespace LuzPropria\LuzPanel\Controller\Security;

use LuzPropria\LuzPanel\Controller\ContainerAware;
use LuzPropria\LuzPanel\Form\AdocoesTypeForm;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Filesystem\Filesystem;

class AdocoesTypeController extends ContainerAware
{
    /**
     * Lista.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $adocoes_type = $this->db()->fetchAll('SELECT * FROM `adocoes_type`');

        // foreach ($institutional_type as $key => $bnty) {
        //     $institutional_type[$key]['total_institucional'] = (int) $this->db()->fetchAssoc('SELECT COUNT(1) as total FROM `institutional` WHERE `type` = ?;', array($bnty['id']))['total'];
        // }

        return $this->render('list.twig', array(
            'data' => $adocoes_type,
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
        $adocoes_type = array();

        $form = $this->createForm(new AdocoesTypeForm(), $adocoes_type, array(
            'action' => $this->get('url_generator')->generate('s_adocoes_type_create'),
            'method' => 'POST',
        ));

        if ('POST' == $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $data = $form->getData();

                // $data['url'] = $this->get('slugify')->slugify($data['url']);

                if ($data['image'] instanceof UploadedFile) {
                    $image = $data['image'];
                    $fs = new Filesystem();
                    $directory = web_path('upload/adocoes');

                    $image_name = sha1(uniqid(mt_rand(), true)).'.'.$image->guessExtension();

                    if (!$fs->exists($directory)) {
                        $fs->mkdir($directory, 0777);
                    }

                    $image->move($directory, $image_name);
                    $fs->chmod($directory.'/'.$image_name, 0777);

                    $data['image'] = $image_name;
                }

                try {
                    $insert_query = 'INSERT INTO `adocoes_type` (`nome`, `cidade`, `image`, `enabled`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, NOW(), NOW())';
                    $this->db()->executeUpdate($insert_query, array($data['nome'], $data['cidade'], $data['image'], $data['enabled']));

                    $this->flashMessage()->add('success', array('message' => 'Adicionado com sucesso.'));

                    return $this->redirect('s_adocoes_type');
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
        $adocoes_type = $this->get('db')->fetchAssoc('SELECT * FROM `adocoes_type` WHERE `id` = ? LIMIT 1;', array($id));

        if ($adocoes_type === false) {
            $this->flashMessage()->add('warning', array('message' => 'Desculpe, mais a pagina não foi encontrada.'));

            return $this->redirect('s_adocoes_type');
        }

        $image_name = $adocoes_type['image'];
        unset($adocoes_type['image']);

        $form = $this->createForm(new AdocoesTypeForm(), $adocoes_type, array(
            'action' => $this->get('url_generator')->generate('s_adocoes_type_edit', array('id' => $id)),
            'method' => 'POST',
        ));

        if ('POST' == $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $data = $form->getData();

                // $data['url'] = $this->get('slugify')->slugify($data['url']);

                if ($data['image'] instanceof UploadedFile) {
                    $image = $data['image'];
                    $fs = new Filesystem();
                    $directory = web_path('upload/adocoes');

                    $image_name = sha1(uniqid(mt_rand(), true)).'.'.$image->guessExtension();

                    if (!$fs->exists($directory)) {
                        $fs->mkdir($directory, 0777);
                    }

                    $image->move($directory, $image_name);
                    $fs->chmod($directory.'/'.$image_name, 0777);

                }
                    $data['image'] = $image_name;

                try {
                    $update_query = 'UPDATE `adocoes_type` SET `nome` = ?, `cidade` = ?, `image` = ?, `enabled` = ?, `updated_at` = NOW() WHERE `id` = ?';
                    $this->get('db')->executeUpdate($update_query, array($data['nome'], $data['cidade'], $data['image'], $data['enabled'], $data['id']));

                    $this->flashMessage()->add('success', array('message' => 'Editado com sucesso.'));

                    return $this->redirect('s_adocoes_type');
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
        $row_sql = $this->get('db')->fetchAssoc('SELECT * FROM `adocoes_type` WHERE `id` = ? LIMIT 1;', array($id));

        if ($row_sql === false) {
            $this->flashMessage()->add('warning', array('message' => 'Desculpe, mais não foi encontrado.'));
        } else {
            $this->get('db')->executeUpdate('DELETE FROM `adocoes_type` WHERE `id` = ?', array($id));

            $this->flashMessage()->add('success', array('message' => 'Deletado com sucesso.'));
        }

        return $this->redirect('s_adocoes_type');
    }
}
