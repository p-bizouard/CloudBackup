<?php

namespace App\Controller\Admin;

use App\Entity\S3Bucket;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class S3BucketCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return S3Bucket::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'S3 bucket')
            ->setPageTitle('new', 'New S3 bucket')
            ->setPageTitle('detail', fn (S3Bucket $entity) => (string) $entity)
            ->setPageTitle('edit', fn (S3Bucket $entity) => sprintf('Edit <b>%s</b>', $entity))
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('name'),
            TextField::new('accessKey')->hideOnIndex(),
            TextField::new('secretKey')
                ->hideOnIndex()
                ->addCssClass('blur-input'),
            TextField::new('bucket')
                ->setHelp('If supported on your S3 provider, you can mount a subdirectory (`bucket:/subdirectory/`)'),
            TextField::new('region'),
            TextField::new('endpointUrl')->hideOnIndex()
                ->setHelp('OVH : `https://s3.<region>.cloud.ovh.net`'),
            BooleanField::new('usePathRequestStyle')->hideOnIndex()
                ->setHelp('See https://github.com/s3fs-fuse/s3fs-fuse/wiki/Non-Amazon-S3'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
