<?php

declare(strict_types=1);

namespace MDClub\Service;

use MDClub\Constant\ApiError;
use MDClub\Exception\ApiException;
use MDClub\Exception\ValidationException;
use MDClub\Helper\Request;
use MDClub\Helper\Validator;
use MDClub\Traits\Brandable;
use MDClub\Traits\Url;
use Psr\Http\Message\UploadedFileInterface;

/**
 * 话题创建与更新
 */
class TopicUpdate extends Abstracts
{
    use Brandable, Url;

    /**
     * 图片类型
     *
     * @return string
     */
    protected function getBrandType(): string
    {
        return 'topic-cover';
    }

    /**
     * 图片尺寸，宽高比为 0.56
     *
     * @return array
     */
    protected function getBrandSize(): array
    {
        return [
            's' => [360, 202],
            'm' => [720, 404],
            'l' => [1080, 606],
        ];
    }

    /**
     * 获取默认的封面图片地址
     *
     * @return array
     */
    protected function getDefaultBrandUrls(): array
    {
        $suffix = Request::isSupportWebp($this->request) ? 'webp' : 'jpg';
        $staticUrl = $this->getStaticUrl();
        $data['o'] = "{$staticUrl}default/topic_cover.{$suffix}";

        foreach (array_keys($this->getBrandSize()) as $size) {
            $data[$size] = "{$staticUrl}default/topic_cover_{$size}.{$suffix}";
        }

        return $data;
    }

    /**
     * 创建话题前的字段验证
     *
     * @param string                $name
     * @param string                $description
     * @param UploadedFileInterface $cover
     */
    protected function createValidation(string $name, string $description, UploadedFileInterface $cover = null): void
    {
        $errors = [];

        // 验证名称
        if (!$name) {
            $errors['name'] = '名称不能为空';
        } elseif (!Validator::isMax($name, 20)) {
            $errors['name'] = '名称长度不能超过 20 个字符';
        }

        // 验证描述
        if (!$description) {
            $errors['description'] = '描述不能为空';
        } elseif (!Validator::isMax($description, 1000)) {
            $errors['description'] = '描述不能超过 1000 个字符';
        }

        // 验证图片
        if (!$cover) {
            $errors['cover'] = '请选择要上传的封面图片';
        } elseif ($coverError = $this->validateImage($cover)) {
            $errors['cover'] = $coverError;
        }

        if (!$errors && $this->topicModel->where('name', $name)->has()) {
            $errors['name'] = '该名称已存在';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }
    }

    /**
     * 创建话题
     *
     * @param  string                $name
     * @param  string                $description
     * @param  UploadedFileInterface $cover
     * @return int
     */
    public function create(string $name, string $description, UploadedFileInterface $cover = null): int
    {
        $this->createValidation($name, $description, $cover);

        // 添加话题
        $topicId = (int)$this->topicModel->insert([
            'name' => $name,
            'description' => $description,
        ]);

        // 保存图片
        $fileName = $this->uploadImage($topicId, $cover);

        // 更新图片到话题信息中
        $this->topicModel
            ->where('topic_id', $topicId)
            ->update('cover', $fileName);

        return $topicId;
    }

    /**
     * 更新话题前的字段验证
     *
     * @param  int                   $topicId
     * @param  string                $name
     * @param  string                $description
     * @param  UploadedFileInterface $cover
     * @return array                 $topicInfo
     */
    private function updateValidation(
        int $topicId,
        string $name = null,
        string $description = null,
        UploadedFileInterface $cover = null
    ): array {
        $topicInfo = $this->topicModel->get($topicId);

        if (!$topicInfo) {
            throw new ApiException(ApiError::TOPIC_NOT_FOUND);
        }

        $errors = [];

        // 验证名称
        if (!is_null($name)) {
            if (!$name) {
                $errors['name'] = '名称不能为空';
            } elseif (!Validator::isMax($name, 20)) {
                $errors['name'] = '名称长度不能超过 20 个字符';
            }
        }

        // 验证描述
        if (!is_null($description)) {
            if (!$description) {
                $errors['description'] = '描述不能为空';
            } elseif (!Validator::isMax($description, 1000)) {
                $errors['description'] = '描述不能超过 1000 个字符';
            }
        }

        // 验证图片
        if (!is_null($cover)) {
            if ($coverError = $this->validateImage($cover)) {
                $errors['cover'] = $coverError;
            }
        }

        if (
            !$errors
            && !is_null($name)
            && $this->topicModel->where(['name' => $name, 'topic_id[!]' => $topicId])->has()
        ) {
            $errors['name'] = '该名称已存在';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return $topicInfo;
    }

    /**
     * 更新话题
     *
     * @param  int                   $topicId
     * @param  string                $name
     * @param  string                $description
     * @param  UploadedFileInterface $cover
     */
    public function update(
        int $topicId,
        string $name = null,
        string $description = null,
        UploadedFileInterface $cover = null
    ): void {
        $topicInfo = $this->updateValidation($topicId, $name, $description, $cover);

        $data = [];
        !is_null($name) && $data['name'] = $name;
        !is_null($description) && $data['description'] = $description;

        if ($data) {
            $this->topicModel
                ->where('topic_id', $topicId)
                ->update($data);
        }

        if (!is_null($cover)) {
            // 先删除旧图片
            $this->deleteImage($topicId, $topicInfo['cover']);

            // 上传并更新图片
            $fileName = $this->uploadImage($topicId, $cover);
            $this->topicModel
                ->where('topic_id', $topicId)
                ->update('cover', $fileName);
        }
    }
}