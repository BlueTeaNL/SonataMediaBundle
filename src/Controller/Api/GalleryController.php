<?php

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\MediaBundle\Controller\Api;

use FOS\RestBundle\Context\Context;
use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\Controller\Annotations\View;
use FOS\RestBundle\Request\ParamFetcherInterface;
use FOS\RestBundle\View\View as FOSRestView;
use JMS\Serializer\SerializationContext;
use Nelmio\ApiDocBundle\Annotation\Operation;
use Nelmio\ApiDocBundle\Annotation\Model;
use Swagger\Annotations as SWG;
use Sonata\DatagridBundle\Pager\PagerInterface;
use Sonata\MediaBundle\Model\GalleryHasMediaInterface;
use Sonata\MediaBundle\Model\GalleryInterface;
use Sonata\MediaBundle\Model\GalleryManagerInterface;
use Sonata\MediaBundle\Model\MediaInterface;
use Sonata\MediaBundle\Model\MediaManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @author Hugo Briand <briand@ekino.com>
 */
class GalleryController
{
    /**
     * @var GalleryManagerInterface
     */
    protected $galleryManager;

    /**
     * @var MediaManagerInterface
     */
    protected $mediaManager;

    /**
     * @var FormFactoryInterface
     */
    protected $formFactory;

    /**
     * @var string
     */
    protected $galleryHasMediaClass;

    /**
     * Constructor.
     *
     * @param GalleryManagerInterface $galleryManager
     * @param MediaManagerInterface   $mediaManager
     * @param FormFactoryInterface    $formFactory
     * @param string                  $galleryHasMediaClass
     */
    public function __construct(GalleryManagerInterface $galleryManager, MediaManagerInterface $mediaManager, FormFactoryInterface $formFactory, $galleryHasMediaClass)
    {
        $this->galleryManager = $galleryManager;
        $this->mediaManager = $mediaManager;
        $this->formFactory = $formFactory;
        $this->galleryHasMediaClass = $galleryHasMediaClass;
    }

    /**
     * Retrieves the list of galleries (paginated).
     *
     * @param ParamFetcherInterface $paramFetcher
     *
     * @return PagerInterface
     */
    public function getGalleriesAction(ParamFetcherInterface $paramFetcher)
    {
        $orderByQueryParam = new QueryParam();
        $orderByQueryParam->name = 'orderBy';
        $orderByQueryParam->requirements = 'ASC|DESC';
        $orderByQueryParam->nullable = true;
        $orderByQueryParam->strict = true;
        $orderByQueryParam->description = 'Query groups order by clause (key is field, value is direction)';
        if (property_exists($orderByQueryParam, 'map')) {
            $orderByQueryParam->map = true;
        } else {
            $orderByQueryParam->array = true;
        }

        $paramFetcher->addParam($orderByQueryParam);

        $supportedCriteria = [
            'enabled' => '',
        ];

        $page = $paramFetcher->get('page');
        $limit = $paramFetcher->get('count');
        $sort = $paramFetcher->get('orderBy');
        $criteria = array_intersect_key($paramFetcher->all(), $supportedCriteria);

        foreach ($criteria as $key => $value) {
            if (null === $value) {
                unset($criteria[$key]);
            }
        }

        if (!$sort) {
            $sort = [];
        } elseif (!is_array($sort)) {
            $sort = [$sort => 'asc'];
        }

        return $this->getGalleryManager()->getPager($criteria, $page, $limit, $sort);
    }

    /**
     * Retrieves a specific gallery.
     *
     * @param $id
     *
     * @return GalleryInterface
     */
    public function getGalleryAction($id)
    {
        return $this->getGallery($id);
    }

    /**
     * Retrieves the medias of specified gallery.
     *
     * @param $id
     *
     * @return MediaInterface[]
     */
    public function getGalleryMediasAction($id)
    {
        $ghms = $this->getGallery($id)->getGalleryHasMedias();

        $media = [];
        foreach ($ghms as $ghm) {
            $media[] = $ghm->getMedia();
        }

        return $media;
    }

    /**
     * Retrieves the galleryhasmedias of specified gallery.
     *
     * @param $id
     *
     * @return GalleryHasMediaInterface[]
     */
    public function getGalleryGalleryhasmediasAction($id)
    {
        return $this->getGallery($id)->getGalleryHasMedias();
    }

    /**
     * Adds a gallery.
     *
     * @param Request $request A Symfony request
     *
     * @throws NotFoundHttpException
     *
     * @return GalleryInterface
     */
    public function postGalleryAction(Request $request)
    {
        return $this->handleWriteGallery($request);
    }

    /**
     * Updates a gallery.
     *
     * @param int     $id      User id
     * @param Request $request A Symfony request
     *
     * @throws NotFoundHttpException
     *
     * @return GalleryInterface
     */
    public function putGalleryAction($id, Request $request)
    {
        return $this->handleWriteGallery($request, $id);
    }

    /**
     * Adds a media to a gallery.
     *
     * @param int     $galleryId A gallery identifier
     * @param int     $mediaId   A media identifier
     * @param Request $request   A Symfony request
     *
     * @throws NotFoundHttpException
     *
     * @return GalleryInterface
     */
    public function postGalleryMediaGalleryhasmediaAction($galleryId, $mediaId, Request $request)
    {
        $gallery = $this->getGallery($galleryId);
        $media = $this->getMedia($mediaId);

        foreach ($gallery->getGalleryHasMedias() as $galleryHasMedia) {
            if ($galleryHasMedia->getMedia()->getId() == $media->getId()) {
                return FOSRestView::create([
                    'error' => sprintf('Gallery "%s" already has media "%s"', $galleryId, $mediaId),
                ], 400);
            }
        }

        return $this->handleWriteGalleryhasmedia($gallery, $media, null, $request);
    }

    /**
     * Updates a media to a gallery.
     *
     * @param int     $galleryId A gallery identifier
     * @param int     $mediaId   A media identifier
     * @param Request $request   A Symfony request
     *
     * @throws NotFoundHttpException
     *
     * @return GalleryInterface
     */
    public function putGalleryMediaGalleryhasmediaAction($galleryId, $mediaId, Request $request)
    {
        $gallery = $this->getGallery($galleryId);
        $media = $this->getMedia($mediaId);

        foreach ($gallery->getGalleryHasMedias() as $galleryHasMedia) {
            if ($galleryHasMedia->getMedia()->getId() == $media->getId()) {
                return $this->handleWriteGalleryhasmedia($gallery, $media, $galleryHasMedia, $request);
            }
        }

        throw new NotFoundHttpException(sprintf('Gallery "%s" does not have media "%s"', $galleryId, $mediaId));
    }

    /**
     * Deletes a media association to a gallery.
     *
     * @param int $galleryId A gallery identifier
     * @param int $mediaId   A media identifier
     *
     * @throws NotFoundHttpException
     *
     * @return View
     */
    public function deleteGalleryMediaGalleryhasmediaAction($galleryId, $mediaId)
    {
        $gallery = $this->getGallery($galleryId);
        $media = $this->getMedia($mediaId);

        foreach ($gallery->getGalleryHasMedias() as $key => $galleryHasMedia) {
            if ($galleryHasMedia->getMedia()->getId() == $media->getId()) {
                $gallery->getGalleryHasMedias()->remove($key);
                $this->getGalleryManager()->save($gallery);

                return ['deleted' => true];
            }
        }

        return FOSRestView::create([
            'error' => sprintf('Gallery "%s" does not have media "%s" associated', $galleryId, $mediaId),
        ], 400);
    }

    /**
     * Deletes a gallery.
     *
     * @param int $id A Gallery identifier
     *
     * @throws NotFoundHttpException
     *
     * @return View
     */
    public function deleteGalleryAction($id)
    {
        $gallery = $this->getGallery($id);

        $this->galleryManager->delete($gallery);

        return ['deleted' => true];
    }

    /**
     * Write a GalleryHasMedia, this method is used by both POST and PUT action methods.
     *
     * @param GalleryInterface         $gallery
     * @param MediaInterface           $media
     * @param GalleryHasMediaInterface $galleryHasMedia
     * @param Request                  $request
     *
     * @return FormInterface
     */
    protected function handleWriteGalleryhasmedia(GalleryInterface $gallery, MediaInterface $media, GalleryHasMediaInterface $galleryHasMedia = null, Request $request)
    {
        $form = $this->formFactory->createNamed(null, 'sonata_media_api_form_gallery_has_media', $galleryHasMedia, [
            'csrf_protection' => false,
        ]);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $galleryHasMedia = $form->getData();
            $galleryHasMedia->setMedia($media);

            $gallery->addGalleryHasMedias($galleryHasMedia);
            $this->galleryManager->save($gallery);

            $view = FOSRestView::create($galleryHasMedia);

            // BC for FOSRestBundle < 2.0
            if (method_exists($view, 'setSerializationContext')) {
                $serializationContext = SerializationContext::create();
                $serializationContext->setGroups(['sonata_api_read']);
                $serializationContext->enableMaxDepthChecks();
                $view->setSerializationContext($serializationContext);
            } else {
                $context = new Context();
                $context->setGroups(['sonata_api_read']);

                // NEXT_MAJOR: simplify when dropping FOSRest < 2.1
                if (method_exists($context, 'disableMaxDepth')) {
                    $context->disableMaxDepth();
                } else {
                    $context->setMaxDepth(0);
                }
                $view->setContext($context);
            }

            return $view;
        }

        return $form;
    }

    /**
     * Retrieves gallery with id $id or throws an exception if it doesn't exist.
     *
     * @param $id
     *
     * @throws NotFoundHttpException
     *
     * @return GalleryInterface
     */
    protected function getGallery($id)
    {
        $gallery = $this->getGalleryManager()->findOneBy(['id' => $id]);

        if (null === $gallery) {
            throw new NotFoundHttpException(sprintf('Gallery (%d) not found', $id));
        }

        return $gallery;
    }

    /**
     * Retrieves media with id $id or throws an exception if it doesn't exist.
     *
     * @param $id
     *
     * @throws NotFoundHttpException
     *
     * @return MediaInterface
     */
    protected function getMedia($id)
    {
        $media = $this->getMediaManager()->findOneBy(['id' => $id]);

        if (null === $media) {
            throw new NotFoundHttpException(sprintf('Media (%d) not found', $id));
        }

        return $media;
    }

    /**
     * @return GalleryManagerInterface
     */
    protected function getGalleryManager()
    {
        return $this->galleryManager;
    }

    /**
     * @return MediaManagerInterface
     */
    protected function getMediaManager()
    {
        return $this->mediaManager;
    }

    /**
     * Write a Gallery, this method is used by both POST and PUT action methods.
     *
     * @param Request  $request Symfony request
     * @param int|null $id      A Gallery identifier
     *
     * @return View|FormInterface
     */
    protected function handleWriteGallery($request, $id = null)
    {
        $gallery = $id ? $this->getGallery($id) : null;

        $form = $this->formFactory->createNamed(null, 'sonata_media_api_form_gallery', $gallery, [
            'csrf_protection' => false,
        ]);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $gallery = $form->getData();
            $this->galleryManager->save($gallery);

            $view = FOSRestView::create($gallery);

            // BC for FOSRestBundle < 2.0
            if (method_exists($view, 'setSerializationContext')) {
                $serializationContext = SerializationContext::create();
                $serializationContext->setGroups(['sonata_api_read']);
                $serializationContext->enableMaxDepthChecks();
                $view->setSerializationContext($serializationContext);
            } else {
                $context = new Context();
                $context->setGroups(['sonata_api_read']);

                // NEXT_MAJOR: simplify when dropping FOSRest < 2.1
                if (method_exists($context, 'disableMaxDepth')) {
                    $context->disableMaxDepth();
                } else {
                    $context->setMaxDepth(0);
                }
                $view->setContext($context);
            }

            return $view;
        }

        return $form;
    }
}
