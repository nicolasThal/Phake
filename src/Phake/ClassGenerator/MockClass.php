<?php
/*
 * Phake - Mocking Framework
 *
 * Copyright (c) 2010-2012, Mike Lively <m@digitalsandwich.com>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *  *  Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *  *  Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *  *  Neither the name of Mike Lively nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category   Testing
 * @package    Phake
 * @author     Mike Lively <m@digitalsandwich.com>
 * @copyright  2010 Mike Lively <m@digitalsandwich.com>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @link       http://www.digitalsandwich.com/
 */

/**
 * Creates and executes the code necessary to create a mock class.
 *
 * @author Mike Lively <m@digitalsandwich.com>
 */
class Phake_ClassGenerator_MockClass
{
    /**
     * @var \Phake_ClassGenerator_ILoader
     */
    private $loader;

    private $reservedWords = array(
        'abstract',
        'and',
        'array',
        'as',
        'break',
        'case',
        'catch',
        'class',
        'clone',
        'const',
        'continue',
        'declare',
        'default',
        'do',
        'else',
        'elseif',
        'enddeclare',
        'endfor',
        'endforeach',
        'endif',
        'endswitch',
        'endwhile',
        'extends',
        'final',
        'for',
        'foreach',
        'function',
        'global',
        'goto',
        'if',
        'implements',
        'interface',
        'instanceof',
        'namespace',
        'new',
        'or',
        'private',
        'protected',
        'public',
        'static',
        'switch',
        'throw',
        'try',
        'use',
        'var',
        'while',
        'xor',
        'die',
        'echo',
        'empty',
        'exit',
        'eval',
        'include',
        'include_once',
        'isset',
        'list',
        'require',
        'require_once',
        'return',
        'print',
        'unset',
        '__halt_compiler'
    );

    /**
     * @param Phake_ClassGenerator_ILoader $loader
     */
    public function __construct(Phake_ClassGenerator_ILoader $loader = null)
    {
        if (empty($loader)) {
            $loader = new Phake_ClassGenerator_EvalLoader();
        }

        $this->loader = $loader;
    }

    /**
     * Generates a new class with the given class name
     *
     * @param string $newClassName - The name of the new class
     * @param string $mockedClassName - The name of the class being mocked
     * @param Phake_Mock_InfoRegistry $infoRegistry

     * @return NULL
     */
    public function generate($newClassName, $mockedClassName, Phake_Mock_InfoRegistry $infoRegistry)
    {
        $extends    = '';
        $implements = '';
        $interfaces = array();
        $constructor = '';

        $mockedClass = new ReflectionClass($mockedClassName);

        if (!$mockedClass->isInterface()) {
            $extends = "extends {$mockedClassName}";
        } elseif ($mockedClassName != 'Phake_IMock') {
            $implements = ", $mockedClassName";

            if ($mockedClass->implementsInterface('Traversable') &&
                !$mockedClass->implementsInterface('Iterator') &&
                !$mockedClass->implementsInterface('IteratorAggregate')
            ) {
                if ($mockedClass->getName() == 'Traversable') {
                    $implements = ', Iterator';
                } else {
                    $implements = ', Iterator'.$implements;
                }
                $interfaces = array('Iterator');
            }
        }

        $classDef = "
class {$newClassName} {$extends}
	implements Phake_IMock {$implements}
{
    public \$__PHAKE_info;

    public static \$__PHAKE_staticInfo;

	const __PHAKE_name = '{$mockedClassName}';

	public \$__PHAKE_constructorArgs;

	{$constructor}

	/**
	 * @return void
	 */
	public function __destruct() {}

 	{$this->generateSafeConstructorOverride($mockedClass)}

	{$this->generateMockedMethods($mockedClass, $interfaces)}
}
";

        $this->loader->loadClassByString($newClassName, $classDef);
        $newClassName::$__PHAKE_staticInfo = $this->createMockInfo($mockedClassName, new Phake_CallRecorder_Recorder(), new Phake_Stubber_StubMapper(), new Phake_Stubber_Answers_NoAnswer());
        $infoRegistry->addInfo($newClassName::$__PHAKE_staticInfo);
    }

    /**
     * Instantiates a new instance of the given mocked class.
     *
     * @param string                      $newClassName
     * @param Phake_CallRecorder_Recorder $recorder
     * @param Phake_Stubber_StubMapper    $mapper
     * @param Phake_Stubber_IAnswer       $defaultAnswer
     * @param array                       $constructorArgs
     *
     * @return Phake_IMock of type $newClassName
     */
    public function instantiate(
        $newClassName,
        Phake_CallRecorder_Recorder $recorder,
        Phake_Stubber_StubMapper $mapper,
        Phake_Stubber_IAnswer $defaultAnswer,
        array $constructorArgs = null
    ) {
        $reflClass = new ReflectionClass($newClassName);
        $constructor = $reflClass->getConstructor();

        if ($constructor == null || ($constructor->class == $newClassName && $constructor->getNumberOfParameters() == 0))
        {
            $mockObject = new $newClassName;
        }
        elseif (version_compare(PHP_VERSION, '5.4.0', '>=')) {
            try {
                $mockObject = $reflClass->newInstanceWithoutConstructor();
            } catch (ReflectionException $ignore) {
            }
        }

        if (empty($mockObject))
        {
            $mockObject = @unserialize(sprintf('O:%d:"%s":0:{}', strlen($newClassName), $newClassName));
            if ($mockObject == null)
            {
                $mockObject = unserialize(sprintf('C:%d:"%s":0:{}', strlen($newClassName), $newClassName));
            }
        }

        try {
            $mockObject = @unserialize(sprintf('O:%d:"%s":0:{}', strlen($newClassName), $newClassName));
            if ($mockObject === false) {
                $mockObject = @unserialize(sprintf('C:%d:"%s":0:{}', strlen($newClassName), $newClassName));
            }
            if (!$mockObject instanceof $newClassName) {
                throw new \Exception('Unserialization failure: ' . $newClassName);
            }
        } catch (\Exception $e) {
            $mockObject = new $newClassName();
        }

        $mockObject->__PHAKE_info = $this->createMockInfo($newClassName::__PHAKE_name, $recorder, $mapper, $defaultAnswer);
        $mockObject->__PHAKE_constructorArgs = $constructorArgs;

        $mockReflClass = new ReflectionClass($mockObject);
        if (null !== $constructorArgs && $mockReflClass->hasMethod('__construct')) {
            call_user_func_array(array($mockObject, '__construct'), $constructorArgs);
        }

        return $mockObject;
    }

    /**
     * Generate mock implementations of all public and protected methods in the mocked class.
     *
     * @param ReflectionClass   $mockedClass
     * @param ReflectionClass[] $mockedInterfaces
     *
     * @return string
     */
    protected function generateMockedMethods(ReflectionClass $mockedClass, array $mockedInterfaces = array())
    {
        $methodDefs = '';
        $filter     = ReflectionMethod::IS_ABSTRACT | ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PUBLIC | ~ReflectionMethod::IS_FINAL;

        $implementedMethods = $this->reservedWords;
        foreach ($mockedClass->getMethods($filter) as $method) {
            if (!$method->isConstructor() && !$method->isDestructor() && !$method->isFinal()
                && !in_array($method->getName(), $implementedMethods)
            ) {
                $implementedMethods[] = $method->getName();
                $methodDefs .= $this->implementMethod($method, $method->isStatic()) . "\n";
            }
        }

        foreach ($mockedInterfaces as $interface) {
            $methodDefs .= $this->generateMockedMethods(new ReflectionClass($interface));
        }

        return $methodDefs;
    }


    private function isConstructorDefinedInInterface(ReflectionClass $mockedClass)
    {
        $constructor = $mockedClass->getConstructor();

        if (empty($constructor) && $mockedClass->hasMethod('__construct'))
        {
            $constructor = $mockedClass->getMethod('__construct');
        }

        if (empty($constructor))
        {
            return false;
        }

        $reflectionClass = $constructor->getDeclaringClass();

        if ($reflectionClass->isInterface())
        {
            return true;
        }

        /* @var ReflectionClass $interface */
        foreach ($reflectionClass->getInterfaces() as $interface)
        {
            if ($interface->getConstructor() !== null)
            {
                return true;
            }
        }

        $parent = $reflectionClass->getParentClass();
        if (!empty($parent))
        {
            return $this->isConstructorDefinedInInterface($parent);
        }
        else
        {
            return false;
        }
    }

    private function generateSafeConstructorOverride(ReflectionClass $mockedClass)
    {
        if (!$this->isConstructorDefinedInInterface($mockedClass))
        {
            $constructorDef = "
	public function __construct()
	{
	    {$this->getConstructorChaining($mockedClass)}
	}
";
            return $constructorDef;
        }
        else
        {
            return '';
        }
    }


    /**
     * Creates the constructor implementation
     *
     * @param ReflectionClass $originalClass
     * @return string
     */
    protected function getConstructorChaining(ReflectionClass $originalClass)
    {
        return $originalClass->hasMethod('__construct') ? "

		if (is_array(\$this->__PHAKE_constructorArgs))
		{
			call_user_func_array(array(\$this, 'parent::__construct'), \$this->__PHAKE_constructorArgs);
			\$this->__PHAKE_constructorArgs = null;
		}
		" : "";
    }

    /**
     * Creates the implementation of a single method
     *
     * @param ReflectionMethod $method
     *
     * @return string
     */
    protected function implementMethod(ReflectionMethod $method, $static = false)
    {
        $modifiers = implode(
            ' ',
            Reflection::getModifierNames($method->getModifiers() & ~ReflectionMethod::IS_ABSTRACT)
        );

        $reference = $method->returnsReference() ? '&' : '';

        if ($static)
        {
            $context = '__CLASS__';
        }
        else
        {
            $context = '$this';
        }

        $docComment = $method->getDocComment() ?: '';
        $methodDef = "
	{$docComment}
	{$modifiers} function {$reference}{$method->getName()}({$this->generateMethodParameters($method)})
	{
		\$__PHAKE_args = array();
		{$this->copyMethodParameters($method)}

        \$__PHAKE_info = Phake::getInfo({$context});
		if (\$__PHAKE_info === null) {
		    return null;
		}

		\$__PHAKE_funcArgs = func_get_args();
		\$__PHAKE_answer = \$__PHAKE_info->getHandlerChain()->invoke({$context}, '{$method->getName()}', \$__PHAKE_funcArgs, \$__PHAKE_args);

	    \$__PHAKE_callback = \$__PHAKE_answer->getAnswerCallback({$context}, '{$method->getName()}');
	    \$__PHAKE_result = call_user_func_array(\$__PHAKE_callback, \$__PHAKE_args);
	    \$__PHAKE_answer->processAnswer(\$__PHAKE_result);
	    return \$__PHAKE_result;
	}
";

        return $methodDef;
    }

    /**
     * Generates the code for all the parameters of a given method.
     *
     * @param ReflectionMethod $method
     *
     * @return string
     */
    protected function generateMethodParameters(ReflectionMethod $method)
    {
        $parameters = array();
        foreach ($method->getParameters() as $parameter) {
            $parameters[] = $this->implementParameter($parameter);
        }

        return implode(', ', $parameters);
    }

    /**
     * Generates the code for all the parameters of a given method.
     *
     * @param ReflectionMethod $method
     *
     * @return string
     */
    protected function copyMethodParameters(ReflectionMethod $method)
    {
        $copies = "\$funcGetArgs = func_get_args();\n\t\t\$__PHAKE_numArgs = count(\$funcGetArgs);\n\t\t";
        foreach ($method->getParameters() as $parameter) {
            $pos = $parameter->getPosition();
            $copies .= "if ({$pos} < \$__PHAKE_numArgs) \$__PHAKE_args[] =& \${$parameter->getName()};\n\t\t";
        }

        $copies .= "for (\$__PHAKE_i = " . count(
            $method->getParameters()
        ) . "; \$__PHAKE_i < \$__PHAKE_numArgs; \$__PHAKE_i++) \$__PHAKE_args[] = func_get_arg(\$__PHAKE_i);\n\t\t";

        return $copies;
    }

    /**
     * Generates the code for an individual method parameter.
     *
     * @param ReflectionParameter $parameter
     *
     * @return string
     */
    protected function implementParameter(ReflectionParameter $parameter)
    {
        $default = '';
        $type    = '';

        if ($parameter->isArray()) {
            $type = 'array ';
        } elseif (method_exists($parameter, 'isCallable') && $parameter->isCallable()) {
            $type = 'callable ';
        } elseif ($parameter->getClass() !== null) {
            $type = $parameter->getClass()->getName() . ' ';
        }

        if ($parameter->isDefaultValueAvailable()) {
            $default = ' = ' . var_export($parameter->getDefaultValue(), true);
        } elseif ($parameter->isOptional()) {
            $default = ' = null';
        }

        return $type . ($parameter->isPassedByReference() ? '&' : '') . '$' . $parameter->getName() . $default;
    }

    /**
     * @param $newClassName
     * @param Phake_CallRecorder_Recorder $recorder
     * @param Phake_Stubber_StubMapper $mapper
     * @param Phake_Stubber_IAnswer $defaultAnswer
     * @return Phake_Mock_Info
     */
    private function createMockInfo(
        $className,
        Phake_CallRecorder_Recorder $recorder,
        Phake_Stubber_StubMapper $mapper,
        Phake_Stubber_IAnswer $defaultAnswer
    ) {
        $info = new Phake_Mock_Info($className, $recorder, $mapper, $defaultAnswer);

        $info->setHandlerChain(
            new Phake_ClassGenerator_InvocationHandler_Composite(array(
                new Phake_ClassGenerator_InvocationHandler_FrozenObjectCheck($info),
                new Phake_ClassGenerator_InvocationHandler_CallRecorder($info->getCallRecorder()),
                new Phake_ClassGenerator_InvocationHandler_MagicCallRecorder($info->getCallRecorder()),
                new Phake_ClassGenerator_InvocationHandler_StubCaller($info->getStubMapper(), $info->getDefaultAnswer(
                )),
            ))
        );

        $info->getStubMapper()->mapStubToMatcher(
            new Phake_Stubber_AnswerCollection(new Phake_Stubber_Answers_StaticAnswer('Mock for ' . $info->getName())),
            new Phake_Matchers_MethodMatcher('__toString', null)
        );

        return $info;
    }
}
