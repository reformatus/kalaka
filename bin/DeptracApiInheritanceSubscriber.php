<?php

declare(strict_types=1);

namespace KalakaDev\Deptrac;

use Deptrac\Deptrac\Contract\Analyser\ProcessEvent;
use Deptrac\Deptrac\Core\Dependency\InheritDependency;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Development tooling, loaded by the `services:` block of the root deptrac.yaml.
 *
 * deptrac charges a subclass with what its base class uses. A module's repository extends
 * `Foundation\Api\Repository\Repository`, which uses `Foundation\ObjectCache`, so every repository
 * of every module would be reported as depending on Foundation. S3's D16 lets an `Api` use
 * its own module's root, so a dependency that reaches a subclass only through a base class in a
 * `Kalaka\<Module>\Api\` namespace is not judged. Direct dependencies, and inherited ones whose
 * base class is not in an `Api`, are judged as before.
 */
final class DeptracApiInheritanceSubscriber implements EventSubscriberInterface
{
    public function invoke(ProcessEvent $event): void
    {
        $dependency = $event->dependency;
        if (
            $dependency instanceof InheritDependency
            && preg_match('/^Kalaka\\\\[A-Za-z]+\\\\Api\\\\/', $dependency->originalDependency->getDepender()->toString()) === 1
        ) {
            $event->stopPropagation();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Between MatchingLayersHandler (1) and DependsOnDisallowedLayer (-1).
            ProcessEvent::class => ['invoke', 0],
        ];
    }
}
