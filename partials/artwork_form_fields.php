<?php
/**
 * Shared artwork form fields (title, image, category, description).
 *
 * Expected variables:
 *   array  $values       ['title','description','category_id']
 *   array  $errors       field => message
 *   array  $categories   rows from the categories table
 *   string $previewUrl   image currently shown in the square preview ('' = empty state)
 *   string $originalUrl  (edit page) the saved image the user can revert to, else ''
 *   bool   $hasStage     true when a staged upload is being shown
 */
$previewUrl  = $previewUrl ?? '';
$originalUrl = $originalUrl ?? '';
$hasStage    = !empty($hasStage);
?>
<div class="post-field<?= isset($errors['title']) ? ' has-error' : '' ?>">
    <label class="post-label" for="post-title">Title <span class="post-req" aria-hidden="true">*</span></label>
    <input type="text" id="post-title" name="title" class="post-input"
           maxlength="<?= ART_TITLE_MAX ?>" required
           placeholder="Enter your artwork title"
           value="<?= art_e($values['title']) ?>"
           aria-describedby="error-title">
    <p class="post-error" id="error-title" data-error-for="title"><?= art_e($errors['title'] ?? '') ?></p>
</div>

<div class="post-field<?= isset($errors['image']) ? ' has-error' : '' ?>">
    <span class="post-label" id="image-label">Artwork Image <span class="post-req" aria-hidden="true">*</span></span>
    <div class="post-upload" data-upload
         data-original="<?= art_e($originalUrl) ?>"
         data-staged="<?= $hasStage ? '1' : '0' ?>">
        <input type="file" id="post-image" name="image" class="post-upload__input"
               accept="image/*"
               aria-labelledby="image-label" aria-describedby="error-image">
        <label class="post-upload__box<?= $previewUrl !== '' ? ' has-image' : '' ?>" for="post-image" data-drop>
            <span class="post-upload__empty">
                <span class="post-upload__plus" aria-hidden="true">+</span>
                <strong>Upload your artwork</strong>
                <small>Choose an image to showcase your creativity</small>
                <small>JPG, PNG or WEBP, up to 5 MB</small>
            </span>
            <img class="post-upload__img" alt="Artwork preview"
                 src="<?= art_e($previewUrl) ?>"<?= $previewUrl === '' ? ' hidden' : '' ?>>
        </label>
        <div class="post-upload__actions" data-upload-actions<?= $previewUrl === '' ? ' hidden' : '' ?>>
            <button type="button" class="post-link-btn" data-upload-change>Change image</button>
            <button type="button" class="post-link-btn" data-upload-reset hidden></button>
        </div>
    </div>
    <input type="hidden" name="stage_token" value="<?= art_e($stage['token'] ?? '') ?>" data-stage-token>
    <p class="post-error post-error--center" id="error-image" data-error-for="image"><?= art_e($errors['image'] ?? '') ?></p>
</div>

<fieldset class="post-field post-fieldset<?= isset($errors['category']) ? ' has-error' : '' ?>">
    <legend class="post-label">Select Artwork Category <span class="post-req" aria-hidden="true">*</span></legend>
    <div class="post-cats" role="radiogroup" aria-describedby="error-category">
        <?php foreach ($categories as $c):
            $cid     = (int) $c['id'];
            $checked = ((int) $values['category_id'] === $cid);
        ?>
            <label class="post-cat<?= $checked ? ' is-selected' : '' ?>">
                <input type="radio" name="category_id" value="<?= $cid ?>" class="post-cat__input"
                       <?= $checked ? 'checked' : '' ?> required>
                <span class="post-cat__media">
                    <?php if (!empty($c['image_path'])): ?>
                        <img src="<?= art_e(art_asset_url($c['image_path'])) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="post-cat__fallback" aria-hidden="true"><?= art_e(mb_substr($c['name'], 0, 1)) ?></span>
                    <?php endif; ?>
                    <span class="post-cat__check" aria-hidden="true"></span>
                </span>
                <span class="post-cat__name"><?= art_e($c['name']) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <p class="post-error" id="error-category" data-error-for="category"><?= art_e($errors['category'] ?? '') ?></p>
</fieldset>

<div class="post-field<?= isset($errors['description']) ? ' has-error' : '' ?>">
    <label class="post-label" for="post-description">Description <span class="post-optional">(optional)</span></label>
    <textarea id="post-description" name="description" class="post-textarea"
              maxlength="<?= ART_DESCRIPTION_MAX ?>" rows="5"
              placeholder="Tell us about your artwork..."
              aria-describedby="error-description"><?= art_e($values['description']) ?></textarea>
    <div class="post-counter" aria-hidden="true"><span data-counter>0</span> / <?= ART_DESCRIPTION_MAX ?></div>
    <p class="post-error" id="error-description" data-error-for="description"><?= art_e($errors['description'] ?? '') ?></p>
</div>
