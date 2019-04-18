<?php

namespace Railken\Amethyst\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Railken\Amethyst\Exceptions;
use Railken\Amethyst\Models\Importer;
use Railken\Lem\Contracts\AgentContract;
use Railken\Template\Generators;
use Symfony\Component\Yaml\Yaml;

abstract class ImportCommonFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var \Railken\Amethyst\Models\Importer
     */
    protected $importer;

    /**
     * @var string
     */
    protected $filePath;

    /**
     * @var \Railken\Lem\Contracts\AgentContract|null
     */
    protected $agent;

    /**
     * Create a new job instance.
     *
     * @param \Railken\Amethyst\Models\Importer    $importer
     * @param string                               $filePath
     * @param \Railken\Lem\Contracts\AgentContract $agent
     */
    public function __construct(Importer $importer, string $filePath, AgentContract $agent = null)
    {
        $this->importer = $importer;
        $this->filePath = $filePath;
        $this->agent = $agent;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $reader = $this->getReader($this->filePath);

        $generator = new Generators\TextGenerator();

        $genFile = $generator->generateViewFile($this->importer->data);

        $importer = $this->importer;

        try {
            $this->read($reader, function ($row, $index) use ($generator, $genFile, $importer) {
                $results = Collection::make();

                $manager = $importer->data_builder->newInstanceData()->getManager();

                $data = Yaml::parse($generator->render($genFile, ['record' => $row]));

                if ($data === null) {
                    throw new Exceptions\ImportFormattingException(sprintf('Error while formatting row #%s', $index));
                }

                $resources = $importer->data_builder->newInstanceQuery($data)->get();

                if ($resources->count() > 0) {
                    foreach ($resources as $resource) {
                        $results[] = $manager->update($resource, $data);
                    }
                } else {
                    $results[] = $manager->create($data);
                }

                foreach ($results as $result) {
                    if (!$result->ok()) {
                        throw new Exceptions\ImportFailedException('Row(#'.$index.'): '.$result->getError(0)->getMessage());
                    }
                }
            });
        } catch (Exceptions\ImportFormattingException | \PDOException | \Railken\SQ\Exceptions\QuerySyntaxException | Exceptions\ImportFailedException $e) {
            unlink($this->filePath);

            return event(new \Railken\Amethyst\Events\ImportFailed($importer, $e, $this->agent));
        } catch (\Twig_Error $e) {
            $e = new \Exception($e->getRawMessage().' on line '.$e->getTemplateLine());

            unlink($this->filePath);

            return event(new \Railken\Amethyst\Events\ImportFailed($importer, $e, $this->agent));
        }

        unlink($this->filePath);
        event(new \Railken\Amethyst\Events\ImportSucceeded($importer, $this->agent));
    }

    /**
     * Retrieve a generic reader.
     *
     * @param string $filePath
     *
     * @return mixed
     */
    abstract public function getReader(string $filePath);

    /**
     * Read.
     *
     * @param mixed   $reader
     * @param Closure $callback
     */
    abstract public function read($reader, Closure $callback);
}
