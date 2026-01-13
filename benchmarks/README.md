### Container Resolution Paths

When `$container->get($id)` is called, resolution follows this order:

| Path | Code | When |
|------|------|------|
| Cache | `return $this->values[$id]` | Service already resolved |
| Builder | `$this->get($builder)->build()` | Builder class registered |
| Factory | `$this->factories[$id]($this)` | Factory closure registered |
| Autowiring | `createService($id)` | Reflect class, resolve dependencies |

### Benchmark Coverage

| Benchmark | Path | Scenario |
|-----------|------|----------|
| LinearChainCold | Autowiring | 100-class dependency chain |
| LinearChainCached | Cache | Singleton retrieval |
| IndependentClassesCold | Autowiring | 100 classes, no dependencies |
| DeepChainCold | Autowiring | 500-class chain (stress test) |
| Builder | Builder | Builder with N parallel dependencies |
| FactoryNoParams | Factory | Simple factory closure |
| FactoryWithContainer | Factory | Factory receiving container |
