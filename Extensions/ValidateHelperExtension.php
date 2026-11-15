<?php


namespace Okay\Modules\Sviat\PersonalData\Extensions;


use Okay\Core\Request;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Extender\ExtensionInterface;

class ValidateHelperExtension implements ExtensionInterface
{
    /**
     * @var Request
     */
    private $request;

    /**
     * @var FrontTranslations
     */
    private $frontTranslations;

    /**
     * Response прибрано з сигнатури: клас ним не користувався, а присвоєння
     * йшло у неоголошену властивість — PHP 8.2+ створював динамічну властивість
     * і писав deprecation на кожному запиті зі сторінкою, де є хоч одна з шести
     * форм. Відповідний аргумент прибрано і з Init/services.php.
     */
    public function __construct(
        Request $request,
        FrontTranslations $frontTranslations
    )
    {
        $this->request           = $request;
        $this->frontTranslations = $frontTranslations;
    }

    public function extendGetUserRegisterError($error)
    {
        if (empty($error) && $this->notConfirmedDataProcessing()) {
            $error = 'empty_name';
            $this->replaceErrorMessage();
        }
        return $error;
    }

    public function extendGetFeedbackValidateError($error)
    {
        if (empty($error) && $this->notConfirmedDataProcessing()) {
            $error = 'empty_name';
            $this->replaceErrorMessage();
        }
        return $error;
    }

    public function extendGetCartValidateError($error)
    {
        if (empty($error) && $this->notConfirmedDataProcessing()) {
            $error = 'empty_name';
            $this->replaceErrorMessage();
        }
        return $error;
    }

    public function extendGetCallbackValidateError($error)
    {
        if (empty($error) && $this->notConfirmedDataProcessing()) {
            $error = 'empty_name';
            $this->replaceErrorMessage();
        }
        return $error;
    }

    public function extendGetCommentValidateError($error)
    {
        if (empty($error) && $this->notConfirmedDataProcessing()) {
            $error = 'empty_name';
            $this->replaceErrorMessage();
        }
        return $error;
    }



    public function extendGetSubscribeValidateError($error)
    {
        if (empty($error) && $this->notConfirmedDataProcessing()) {
            $error = 'empty_name';
            $this->replaceErrorMessage();
        }
        return $error;
    }

    private function replaceErrorMessage()
    {
        $this->frontTranslations->addTranslation(
            'form_enter_name',
            $this->frontTranslations->getTranslation('sviat__personal_data__confirm_error_message')
        );
    }

    private function notConfirmedDataProcessing()
    {
        return $this->request->post('sviat__personal_data__need_confirm') && ! $this->request->post('sviat__personal_data__confirm');
    }
}