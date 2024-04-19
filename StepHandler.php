<?php

namespace App\Extensions\SourceHandlers\Helpers;

use App\Extensions\SourceHandlers\Sources\BaseSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Throwable;

class StepHandler extends BaseHandler implements HandlerContract
{
    /** @var BaseSource  */
    protected BaseSource $source;

    /**
     * @param BaseSource $source
     */
    public function __construct(BaseSource $source)
    {
        $this->source = $source;
    }

    /**
     * Создает пустой batch, возвращает его ид
     *
     * @return string
     * @throws Throwable
     */
    protected function initBatch(): string
    {
        $batch = Bus::batch([])
            ->name($this->getSource()->getBatchName())
            ->onConnection($this->getSource()->getConnectionName())
            ->onQueue($this->getSource()->getQueueName())
            ->dispatch();

        return $batch->id;
    }

    /**
     * Добавляет в batch новую job
     *
     * @param ShouldQueue $job
     * @return void
     */
    protected function addJobToBatch(ShouldQueue $job): void
    {
        $batchId = $this->getSource()->getOptionHelper()->getOption('batchId');
        $batch   = Bus::findBatch($batchId);

        if (!$batch || $batch->cancelled()) {
            return;
        }

        $batch->add($job);
    }

    /**
     * Запуск первого шага
     *
     * @return void
     * @throws Throwable
     */
    public function runFirstStep(): void
    {
        $this->getSource()->getOptionHelper()->setOption('batchId', $this->initBatch());

        $job = $this->getNextStepJob();

        $job && $this->addJobToBatch($job);
    }

    /**
     * Запускает выполнение текущего шага еще раз
     * Передает в него данные с предыдущего шага и параметры запуска в виде коллекции
     *
     * @param Collection|null $prevData
     * @return void
     */
    public function stepRepeat(?Collection $prevData = null): void
    {
        $job = $this->getCurrentStepJob($prevData);

        $job && $this->addJobToBatch($job);
    }

    /**
     * Запускает выполнение следующего шага, если он есть
     * Передает в него данные с предыдущего шага и параметры запуска в виде коллекции
     *
     * @param Collection|null $prevData
     * @return void
     */
    public function stepComplete(?Collection $prevData = null): void
    {
        $job = $this->getNextStepJob($prevData);

        $job && $this->addJobToBatch($job);
    }

    /**
     * Возвращает объект ShouldQueue для обработки следующего шага
     *
     * @param Collection|null $reply
     * @return ShouldQueue|null
     */
    protected function getNextStepJob(?Collection $reply = null): ?ShouldQueue
    {
        $nextStep = $this->getSource()->getOptionHelper()->getNextStep();
        if ($nextStep->isEmpty()) {
            return null;
        }

        return $this->getJob($reply);
    }

    /**
     * Возвращает объект ShouldQueue для обработки текущего шага
     *
     * @param Collection|null $reply
     * @return ShouldQueue|null
     */
    protected function getCurrentStepJob(?Collection $reply = null): ?ShouldQueue
    {
        return $this->getJob($reply);
    }

    /**
     * Создает и возвращает новый объект ShouldQueue для обработки текущего шага
     * Передает в конструктор объект BaseSource, ответ и параметры предыдущего шага
     *
     * @param Collection|null $reply
     * @return ShouldQueue|null
     */
    protected function getJob(?Collection $reply = null): ?ShouldQueue
    {
        if(!$jobClass = $this->getSource()->getOptionHelper()->getStepJob()) {
            return null;
        }

        return new $jobClass($this->getSource(), $reply);
    }

    /**
     * Возвращает текущий объект типа BaseSource
     *
     * @return BaseSource
     */
    protected function getSource(): BaseSource
    {
        return $this->source;
    }

}
